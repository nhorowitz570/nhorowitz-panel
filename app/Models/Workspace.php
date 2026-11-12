<?php

namespace App\Models;

use App\Enums\BillingInterval;
use App\Enums\Feature;
use App\Enums\Limit;
use App\Enums\Plan;
use App\Enums\WorkspacePermission;
use App\Enums\WorkspaceRole;
use App\Support\Admin\Impersonation;
use App\Support\Auth\PasskeySignIn;
use App\Support\Domains\CustomDomains;
use App\Support\Edition;
use App\Support\Entitlements\Entitlements;
use App\Support\Extensions\Billing;
use App\Support\Extensions\Extensions;
use App\Support\Extensions\TrialCards;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Database\Factories\WorkspaceFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

/**
 * @property int $id
 * @property int $owner_id
 * @property string $name
 * @property string $slug
 * @property string|null $logo_path
 * @property Plan|null $plan
 * @property BillingInterval|null $billing_interval
 * @property CarbonImmutable|null $trial_ends_at
 * @property Plan|null $trial_plan
 * @property CarbonImmutable|null $trial_last_ends_at
 * @property CarbonImmutable|null $trial_reminded_3d_at
 * @property CarbonImmutable|null $trial_reminded_1d_at
 * @property CarbonImmutable|null $trial_ended_emailed_at
 * @property CarbonImmutable|null $trial_emails_off_at
 * @property string|null $plan_source
 * @property CarbonImmutable|null $manual_plan_until
 * @property int|null $machine_limit_override
 * @property int|null $mcp_call_limit_override
 * @property string|null $plan_note
 * @property CarbonImmutable|null $cloud_credit_used_at
 * @property CarbonInterface|null $plan_grace_until
 * @property bool $alert_email
 * @property bool $require_two_factor
 * @property string $domain_join
 * @property string $domain_join_role
 * @property int|null $domain_join_custom_role_id
 * @property bool $require_google_sign_in
 * @property bool $require_sso
 * @property string $scim_default_role
 * @property int|null $scim_default_custom_role_id
 * @property string|null $alert_webhook_url
 * @property list<string>|null $alert_muted
 * @property string|null $company_legal_name
 * @property string|null $company_number
 * @property string|null $company_tax_id
 * @property string|null $company_address
 * @property string|null $company_country
 * @property string|null $company_contact_name
 * @property string|null $company_contact_title
 * @property string|null $company_contact_email
 * @property array{segment?: string, servers?: string, team?: bool, recommended?: string, plan?: string, interval?: string, control?: bool, skipped_at?: string|null}|null $welcome
 * @property array<string, string|null>|null $dismissed_issues
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 * @property-read Membership $pivot
 */
#[Fillable(['name', 'slug', 'plan', 'billing_interval', 'trial_ends_at'])]
class Workspace extends Model
{
    /** @use HasFactory<WorkspaceFactory> */
    use HasFactory;

    public const int TRIAL_DAYS = 14;

    public const Plan TRIAL_PLAN = Plan::Pro;

    public const int GRACE_DAYS = 7;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            ...app(Extensions::class)->modelCasts(self::class),
            'plan' => Plan::class,
            'billing_interval' => BillingInterval::class,
            'trial_ends_at' => 'datetime',
            'require_two_factor' => 'boolean',
            'require_google_sign_in' => 'boolean',
            'require_sso' => 'boolean',
            'manual_plan_until' => 'datetime',
            'plan_grace_until' => 'datetime',
            'cloud_credit_used_at' => 'immutable_datetime',
            'machine_limit_override' => 'integer',
            'mcp_call_limit_override' => 'integer',
            'alert_email' => 'boolean',
            'alert_webhook_url' => 'encrypted',
            'alert_muted' => 'array',
            'dismissed_issues' => 'array',
            'welcome' => 'array',
            'onboarded_at' => 'datetime',
            'trial_plan' => Plan::class,
            'trial_reminded_3d_at' => 'datetime',
            'trial_reminded_1d_at' => 'datetime',
            'trial_ended_emailed_at' => 'datetime',
            'trial_emails_off_at' => 'datetime',
            'trial_last_ends_at' => 'datetime',
        ];
    }

    public const array TRIAL_REMINDER_FLAGS = ['trial_reminded_3d_at', 'trial_reminded_1d_at', 'trial_ended_emailed_at'];

    protected static function booted(): void
    {
        static::saving(function (Workspace $workspace): void {
            if (! $workspace->isDirty('trial_ends_at') || $workspace->trial_ends_at === null) {
                return;
            }

            $workspace->trial_last_ends_at = $workspace->trial_ends_at;

            if ($workspace->exists && $workspace->trial_ends_at->isFuture()) {
                $workspace->forceFill(array_fill_keys(self::TRIAL_REMINDER_FLAGS, null));
            }
        });
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_id');
    }

    /** @return BelongsToMany<User, $this, Membership> */
    public function members(): BelongsToMany
    {
        return $this->belongsToMany(User::class)
            ->using(Membership::class)
            ->withPivot('role', 'custom_role_id')
            ->withTimestamps();
    }

    /** @return HasMany<WorkspaceCustomRole, $this> */
    public function customRoles(): HasMany
    {
        return $this->hasMany(WorkspaceCustomRole::class)->orderBy('name');
    }

    public function hasCustomRoles(): bool
    {
        return $this->hasFeature(Feature::CustomRoles);
    }

    public function customRoleOf(User $user): ?WorkspaceCustomRole
    {
        if (! $this->hasCustomRoles()) {
            return null;
        }

        $id = $this->members()->whereKey($user->id)->value('user_workspace.custom_role_id');

        return $id === null ? null : $this->customRoles()->whereKey($id)->first();
    }

    public function satisfiesTwoFactor(User $user, ?Request $request = null): bool
    {
        if (! $this->require_two_factor || $user->two_factor_confirmed_at !== null) {
            return true;
        }

        if ($user->passkeys()->exists() && ($request === null || ! $request->hasSession() || Impersonation::active($request)
            || PasskeySignIn::verified($request, $user))) {
            return true;
        }

        foreach ($request === null ? [] : app(Extensions::class)->signInHooks() as $hooks) {
            if ($hooks->meetsTwoFactor($this, $user, $request)) {
                return true;
            }
        }

        return false;
    }

    public function hasSso(): bool
    {
        return $this->hasFeature(Feature::Sso);
    }

    /** @return HasMany<WorkspaceEmailDomain, $this> */
    public function emailDomains(): HasMany
    {
        return $this->hasMany(WorkspaceEmailDomain::class)->orderBy('domain');
    }

    /** @return HasMany<WorkspaceJoinRequest, $this> */
    public function joinRequests(): HasMany
    {
        return $this->hasMany(WorkspaceJoinRequest::class);
    }

    public function hasGoogleTeamAccess(): bool
    {
        return $this->hasStaffRoles();
    }

    public function satisfiesGoogleSignIn(User $user, ?string $via, ?string $hd): bool
    {
        if (! $this->require_google_sign_in || $this->owner_id === $user->id) {
            return true;
        }

        return $via === 'google' && $hd !== null
            && $this->emailDomains()->where('domain', strtolower($hd))->exists();
    }

    public function hasScim(): bool
    {
        return $this->hasFeature(Feature::Scim);
    }

    /**
     * @return HasMany<Monitor, $this>
     */
    public function monitors(): HasMany
    {
        return $this->hasMany(Monitor::class);
    }

    /** @return HasMany<StatusIncident, $this> */
    public function statusIncidents(): HasMany
    {
        return $this->hasMany(StatusIncident::class);
    }

    /**
     * @return HasMany<Machine, $this>
     */
    public function machines(): HasMany
    {
        return $this->hasMany(Machine::class);
    }

    /** @return HasMany<Project, $this> */
    public function projects(): HasMany
    {
        return $this->hasMany(Project::class);
    }

    /**
     * @return HasMany<MachineGroup, $this>
     */
    public function machineGroups(): HasMany
    {
        return $this->hasMany(MachineGroup::class);
    }

    /**
     * @return HasMany<MachinePolicy, $this>
     */
    public function machinePolicies(): HasMany
    {
        return $this->hasMany(MachinePolicy::class);
    }

    /** @return HasMany<GitHubInstallation, $this> */
    public function githubInstallations(): HasMany
    {
        return $this->hasMany(GitHubInstallation::class);
    }

    /**
     * @return HasMany<BackupDestination, $this>
     */
    public function backupDestinations(): HasMany
    {
        return $this->hasMany(BackupDestination::class);
    }

    /** @return HasMany<WorkspaceInvitation, $this> */
    public function invitations(): HasMany
    {
        return $this->hasMany(WorkspaceInvitation::class);
    }

    public function hasMember(User $user): bool
    {
        return $this->members()->whereKey($user->id)->exists();
    }

    public function roleOf(User $user): ?WorkspaceRole
    {
        $role = $this->members()->whereKey($user->id)->value('user_workspace.role');

        return $role === null ? null : WorkspaceRole::tryFrom($role);
    }

    public function allows(User $user, WorkspacePermission $permission): bool
    {
        return self::grants($this->accessOf($user), $permission);
    }

    /** @return array{role: ?WorkspaceRole, custom: ?WorkspaceCustomRole} */
    public function accessOf(User $user): array
    {
        $role = $this->roleOf($user);

        return ['role' => $role, 'custom' => $role === WorkspaceRole::Owner ? null : $this->customRoleOf($user)];
    }

    /** @param  array{role: ?WorkspaceRole, custom: ?WorkspaceCustomRole}  $access */
    public static function grants(array $access, WorkspacePermission $permission): bool
    {
        if ($access['custom'] !== null) {
            return $access['custom']->grants($permission);
        }

        return $access['role']?->can($permission) ?? false;
    }

    /** @return list<WorkspacePermission> */
    public static function permissionsFor(WorkspaceRole $role, ?WorkspaceCustomRole $custom = null): array
    {
        $access = ['role' => $role, 'custom' => $custom];

        return array_values(array_filter(WorkspacePermission::cases(), fn (WorkspacePermission $p) => self::grants($access, $p)));
    }

    /** @return list<WorkspacePermission> */
    public function permissionsOf(User $user): array
    {
        $access = $this->accessOf($user);

        return $access['role'] === null ? [] : self::permissionsFor($access['role'], $access['custom']);
    }

    /** @param  list<WorkspacePermission>  $permissions */
    public function canGrant(User $actor, array $permissions): bool
    {
        $held = $this->permissionsOf($actor);

        foreach ($permissions as $permission) {
            if (in_array($permission, WorkspacePermission::offered(), true) && ! in_array($permission, $held, true)) {
                return false;
            }
        }

        return true;
    }

    public function hasStaffRoles(): bool
    {
        return $this->hasFeature(Feature::StaffRoles);
    }

    public function logoUrl(): ?string
    {
        return $this->logo_path === null ? null : Storage::disk('public')->url($this->logo_path);
    }

    public function onTrial(): bool
    {
        return $this->plan === null && $this->trial_ends_at?->isFuture() === true;
    }

    public function effectivePlan(): ?Plan
    {
        return $this->plan ?? ($this->onTrial() || $this->awaitingFirstCharge() ? ($this->trial_plan ?? self::TRIAL_PLAN) : null);
    }

    public function isActive(): bool
    {
        return app(Entitlements::class)->active($this);
    }

    public function hasFeature(Feature $feature): bool
    {
        return app(Entitlements::class)->has($this, $feature);
    }

    public function limitOf(Limit $limit): ?int
    {
        return app(Entitlements::class)->limit($this, $limit);
    }

    public function awaitingFirstCharge(): bool
    {
        return $this->plan === null && app(TrialCards::class)->awaitingFirstCharge($this);
    }

    public function machineLimit(): ?int
    {
        return $this->limitOf(Limit::Machines);
    }

    public function mcpCallLimit(): ?int
    {
        return $this->limitOf(Limit::McpCalls);
    }

    public function hasFleetStatusPage(): bool
    {
        return $this->hasFeature(Feature::FleetStatusPage);
    }

    public function hasCustomDomains(): bool
    {
        return $this->hasFeature(Feature::CustomDomains);
    }

    public function hasOwnLogStorage(): bool
    {
        return $this->hasFeature(Feature::OwnLogStorage);
    }

    /** @return HasMany<WorkspaceDomain, $this> */
    public function domains(): HasMany
    {
        return $this->hasMany(WorkspaceDomain::class);
    }

    /** @return HasOne<WorkspaceStatusPage, $this> */
    public function fleetStatusPage(): HasOne
    {
        return $this->hasOne(WorkspaceStatusPage::class);
    }

    public function hasStatusPages(): bool
    {
        return $this->hasFeature(Feature::StatusPages);
    }

    public function hasAutomations(): bool
    {
        return $this->hasFeature(Feature::Automations);
    }

    /**
     * @return HasMany<Workflow, $this>
     */
    public function workflows(): HasMany
    {
        return $this->hasMany(Workflow::class);
    }

    public function logRetentionDays(): int
    {
        return (int) $this->limitOf(Limit::LogRetentionDays);
    }

    public function logLinesPerDay(): int
    {
        return (int) $this->limitOf(Limit::LogLinesPerDay);
    }

    public function activityRetentionDays(): int
    {
        return (int) $this->limitOf(Limit::ActivityRetentionDays);
    }

    public function monitorLimit(): int
    {
        return (int) $this->limitOf(Limit::Monitors);
    }

    public function minMonitorInterval(): int
    {
        return (int) $this->limitOf(Limit::MinMonitorInterval);
    }

    public function canAddMachine(): bool
    {
        $limit = $this->machineLimit();

        return $limit === null || $this->machines()->count() < $limit;
    }

    public function choosePlan(Plan $plan, BillingInterval $interval): void
    {
        $this->forceFill([
            'plan' => $plan,
            'billing_interval' => $interval,
            'trial_ends_at' => null,
        ])->save();
    }

    public function hasManualPlan(): bool
    {
        return $this->plan !== null && $this->plan_source === 'manual';
    }

    /** @return array{plan: string|null, planLabel: string|null, interval: string|null, subscriptionStatus: string|null, graceUntil: string|null, hasBillingPortal: bool, onTrial: bool, trialEndsAt: string|null, trialDaysLeft: int|null, machineCount: int, machineLimit: int|null, canAddMachine: bool, contract: array<string, mixed>|null, selfHosted?: true} */
    public function billingSummary(): array
    {
        $machineCount = $this->machines()->count();
        $limit = $this->machineLimit();
        $onTrial = $this->onTrial();
        $billing = app(Billing::class)->summary($this);

        return [
            'plan' => $this->effectivePlan()?->value,
            'planLabel' => $this->effectivePlan()?->label(),
            'interval' => $this->billing_interval?->value,
            'subscriptionStatus' => $billing['subscriptionStatus'],
            'graceUntil' => $this->plan !== null ? $this->plan_grace_until?->toIso8601String() : null,
            'hasBillingPortal' => $billing['hasBillingPortal'],
            'onTrial' => $onTrial,
            'trialEndsAt' => $onTrial ? $this->trial_ends_at?->toIso8601String() : null,
            'trialDaysLeft' => $onTrial ? max(1, (int) ceil(now()->diffInDays($this->trial_ends_at))) : null,
            'machineCount' => $machineCount,
            'machineLimit' => $limit,
            'canAddMachine' => $limit === null || $machineCount < $limit,
            'contract' => $billing['contract'],
            ...(Edition::selfHosted() ? ['selfHosted' => true] : []),
        ];
    }

    public function host(): string
    {
        return CustomDomains::dashboardHostFor($this->slug) ?? $this->subdomain();
    }

    public function subdomain(): string
    {
        return $this->slug.'.'.config('app.domain');
    }

    /** @return array{id: int, name: string, slug: string, host: string, logoUrl: string|null, billing: array<string, mixed>} */
    public function toPageProps(): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'slug' => $this->slug,
            'host' => $this->host(),
            'logoUrl' => $this->logoUrl(),
            'billing' => $this->billingSummary(),
        ];
    }

    public function url(): string
    {
        return route('workspace.home', ['workspace' => $this->slug]);
    }
}

<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Database\Factories\UserFactory;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Attributes\Appends;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Laravel\Fortify\Contracts\PasskeyUser;
use Laravel\Fortify\PasskeyAuthenticatable;
use Laravel\Fortify\TwoFactorAuthenticatable;

/**
 * @property array<string, string>|null $signup_source
 * @property int $id
 * @property CarbonImmutable|null $password_set_at
 * @property string $name
 * @property string $email
 * @property string|null $avatar_path
 * @property-read string|null $avatar
 * @property CarbonImmutable|null $email_verified_at
 * @property string $password
 * @property string|null $two_factor_secret
 * @property string|null $two_factor_recovery_codes
 * @property CarbonImmutable|null $two_factor_confirmed_at
 * @property CarbonImmutable|null $early_access_at
 * @property string|null $locale
 * @property string|null $nav_layout
 * @property CarbonImmutable|null $mcp_terms_accepted_at
 * @property CarbonImmutable|null $trial_used_at
 * @property bool $is_admin
 * @property CarbonImmutable|null $suspended_at
 * @property string|null $suspended_reason
 * @property string|null $remember_token
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 * @property-read Membership $pivot
 */
#[Fillable(['name', 'email', 'password'])]
#[Hidden(['password', 'two_factor_secret', 'two_factor_recovery_codes', 'remember_token', 'avatar_path'])]
#[Appends(['avatar'])]
class User extends Authenticatable implements MustVerifyEmail, PasskeyUser
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable, PasskeyAuthenticatable, TwoFactorAuthenticatable;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password_set_at' => 'datetime',
            'password' => 'hashed',
            'two_factor_confirmed_at' => 'datetime',
            'early_access_at' => 'datetime',
            'mcp_terms_accepted_at' => 'datetime',
            'trial_used_at' => 'datetime',
            'changelog_seen_at' => 'datetime',
            'is_admin' => 'boolean',
            'suspended_at' => 'datetime',
            'signup_source' => 'array',
        ];
    }

    /** @return Attribute<string|null, never> */
    protected function avatar(): Attribute
    {
        return Attribute::get(fn () => $this->avatar_path === null
            ? null
            : Storage::disk('public')->url($this->avatar_path));
    }

    /**
     * @return BelongsToMany<Workspace, $this, Membership>
     */
    public function workspaces(): BelongsToMany
    {
        return $this->belongsToMany(Workspace::class)
            ->using(Membership::class)
            ->withPivot('role', 'pinned_at')
            ->withTimestamps();
    }

    /** @return HasMany<Workspace, $this> */
    public function ownedWorkspaces(): HasMany
    {
        return $this->hasMany(Workspace::class, 'owner_id');
    }

    /** @return HasMany<SocialAccount, $this> */
    public function socialAccounts(): HasMany
    {
        return $this->hasMany(SocialAccount::class);
    }

    /** @return HasMany<UserNote, $this> */
    public function staffNotes(): HasMany
    {
        return $this->hasMany(UserNote::class);
    }

    public function defaultWorkspace(): ?Workspace
    {
        return $this->workspaces()
            ->orderBy('user_workspace.created_at')
            ->orderBy('user_workspace.id')
            ->first();
    }

    public function signOutEverywhere(): void
    {
        DB::table(config('session.table', 'sessions'))->where('user_id', $this->id)->delete();
        $this->forceFill(['remember_token' => Str::random(60)])->saveQuietly();
    }
}

<?php

namespace App\Http\Controllers;

use App\Enums\BillingInterval;
use App\Enums\Currency;
use App\Enums\Plan;
use App\Models\Activity;
use App\Models\MachinePolicy;
use App\Models\Workspace;
use App\Support\Audit\AuditContext;
use App\Support\Audit\Audited;
use App\Support\Audit\NotAudited;
use App\Support\Edition;
use App\Support\Extensions\Prices;
use App\Support\Extensions\TrialCards;
use App\Support\Machines\MachinePolicies;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use RuntimeException;

class WorkspaceWelcomeController extends Controller
{
    public const array SEGMENTS = ['own', 'agency', 'hosting', 'exploring'];

    public const array SERVERS = ['1', '2-5', '6-20', '20+'];

    public function __construct(private readonly TrialCards $cards, private readonly Prices $prices) {}

    public function show(Request $request, Workspace $workspace): Response|RedirectResponse
    {
        if (! $this->welcoming($request, $workspace)) {
            return to_route('workspace.home', ['workspace' => $workspace->slug]);
        }

        $answers = $workspace->welcome ?? [];
        $policy = MachinePolicies::defaultFor($workspace);
        $currency = Currency::forWorkspace($workspace, $request);

        return Inertia::render('welcome/Welcome', [
            'workspace' => $workspace->toPageProps(),
            'answers' => [
                'segment' => $answers['segment'] ?? null,
                'servers' => $answers['servers'] ?? null,
                'team' => $answers['team'] ?? null,
                'plan' => $answers['plan'] ?? null,
                'interval' => $answers['interval'] ?? BillingInterval::Monthly->value,
                'control' => ($answers['control'] ?? false) ? $policy->settings() : null,
            ],
            'presets' => MachinePolicy::PRESETS,
            'recommended' => self::recommend($answers)->value,
            'plans' => array_map(fn (Plan $plan) => [
                'key' => $plan->value,
                'label' => $plan->label(),
                'monthly' => $this->prices->plan($plan, BillingInterval::Monthly, $currency),
                'annual' => $this->prices->plan($plan, BillingInterval::Annual, $currency),
                'machines' => $plan->machineLimit(),
            ], Plan::cases()),
            'currency' => $currency->value,
            'trialDays' => Workspace::TRIAL_DAYS,
            'cardRequired' => $this->needsCard($workspace),
            'cardTrialDays' => $this->cards->trialDays($workspace),
            'cardVerifyUrl' => $this->cards->verifyPath($workspace),
            'canConnect' => (bool) config('daemon.install_ready'),
            'apiUrl' => rtrim((string) config('app.url'), '/').'/api/v1/machines',
            'choosePlan' => ! Edition::selfHosted(),
        ]);
    }

    #[NotAudited('Answers to the welcome questions, kept on the workspace')]
    public function about(Request $request, Workspace $workspace): RedirectResponse
    {
        abort_unless($this->welcoming($request, $workspace), 403);
        $data = $request->validate([
            'segment' => ['required', Rule::in(self::SEGMENTS)],
            'servers' => ['required', Rule::in(self::SERVERS)],
            'team' => ['required', 'boolean'],
        ]);

        $answers = [...($workspace->welcome ?? []), ...$data, 'team' => (bool) $data['team']];
        $answers['recommended'] = self::recommend($answers)->value;
        $workspace->forceFill(['welcome' => $answers])->save();

        if (! ($answers['control'] ?? false)) {
            MachinePolicies::defaultFor($workspace)->fill(MachinePolicy::preset($data['segment']))->save();
        }

        return back();
    }

    #[Audited]
    public function control(Request $request, Workspace $workspace): RedirectResponse
    {
        abort_unless($this->welcoming($request, $workspace), 403);
        $data = $request->validate([
            'discovery' => ['required', 'boolean'],
            'logs' => ['required', 'boolean'],
            'log_ip_masking' => ['required', Rule::in(MachinePolicy::MASKING)],
            'security_watch' => ['required', 'boolean'],
            'error_reports' => ['sometimes', 'boolean'],
            'status_page_new' => ['required', 'boolean'],
            'updates' => ['required', Rule::in(MachinePolicy::UPDATES)],
        ]);

        $policy = MachinePolicies::defaultFor($workspace);
        $policy->fill(array_map(fn ($value) => is_string($value) ? $value : (bool) $value, $data));
        if ($policy->isDirty()) {
            $policy->save();
            $machines = MachinePolicies::following($policy)->pluck('id');
            MachinePolicies::push($machines);
            Activity::record($workspace, 'machine_policy.update', $policy->name, meta: ['machines' => $machines->count()]);
        } else {
            AuditContext::nothingChanged();
        }
        $workspace->forceFill(['welcome' => [...($workspace->welcome ?? []), 'control' => true]])->save();

        return back();
    }

    #[Audited]
    public function plan(Request $request, Workspace $workspace): RedirectResponse
    {
        abort_unless($this->welcoming($request, $workspace), 403);
        abort_if(Edition::selfHosted(), 404);
        abort_if($this->cards->needsCard($workspace), 403, 'Add a card to start your trial.');
        $this->savePlan($request, $workspace);

        return back();
    }

    #[Audited]
    public function card(Request $request, Workspace $workspace): JsonResponse
    {
        abort_if(Edition::selfHosted(), 404);
        abort_unless($this->welcoming($request, $workspace) && $this->cards->needsCard($workspace), 403);
        $data = $this->savePlan($request, $workspace);

        try {
            $checkoutUrl = $this->cards->checkout(
                $workspace->refresh(),
                Plan::from($data['plan']),
                BillingInterval::from($data['interval']),
                $request->user(),
                route('workspace.welcome.show', ['workspace' => $workspace->slug]),
            );
        } catch (ConnectionException) {
            return response()->json(['message' => 'Unable to reach checkout right now. Please try again.'], 503);
        } catch (RuntimeException $exception) {
            return response()->json(['message' => $exception->getMessage()], 503);
        }

        return response()->json(['checkoutUrl' => $checkoutUrl]);
    }

    /** @return array{plan: string, interval: string} */
    private function savePlan(Request $request, Workspace $workspace): array
    {
        $data = $request->validate([
            'plan' => ['required', Rule::enum(Plan::class)],
            'interval' => ['required', Rule::enum(BillingInterval::class)],
        ]);

        $before = $workspace->effectivePlan();
        $workspace->forceFill([
            'welcome' => [...($workspace->welcome ?? []), ...$data],
            'trial_plan' => $workspace->onTrial() ? $data['plan'] : $workspace->trial_plan,
        ])->save();

        if ($workspace->onTrial() && $before !== $workspace->effectivePlan()) {
            Activity::record($workspace, 'workspace.trial_plan', $workspace->effectivePlan()?->label(), meta: ['from' => $before?->label()]);
        }

        return $data;
    }

    #[NotAudited('Marks the welcome flow as seen')]
    public function finish(Request $request, Workspace $workspace): RedirectResponse
    {
        abort_unless($workspace->owner_id === $request->user()->id, 403);
        $data = $request->validate(['skipped_at' => ['nullable', 'string', 'in:about,control,plan,connect']]);

        if ($this->needsCard($workspace)) {
            return to_route('workspace.welcome.show', ['workspace' => $workspace->slug]);
        }

        if ($workspace->onboarded_at === null) {
            $workspace->forceFill([
                'onboarded_at' => now(),
                'welcome' => [...($workspace->welcome ?? []), 'skipped_at' => $data['skipped_at'] ?? null],
            ])->save();
        }

        return to_route('workspace.home', ['workspace' => $workspace->slug]);
    }

    private function needsCard(Workspace $workspace): bool
    {
        return ! Edition::selfHosted() && $this->cards->needsCard($workspace);
    }

    private function welcoming(Request $request, Workspace $workspace): bool
    {
        return $workspace->onboarded_at === null && $workspace->owner_id === $request->user()->id;
    }

    /** @param  array<string, mixed>  $answers */
    public static function recommend(array $answers): Plan
    {
        $servers = $answers['servers'] ?? null;
        $team = (bool) ($answers['team'] ?? false);
        $business = in_array($answers['segment'] ?? null, ['hosting', 'agency'], true) && $team;

        return match (true) {
            $servers === null => Workspace::TRIAL_PLAN,
            in_array($servers, ['6-20', '20+'], true), $business => Plan::Business,
            $servers === '2-5', $team => Plan::Pro,
            default => Plan::Essential,
        };
    }
}

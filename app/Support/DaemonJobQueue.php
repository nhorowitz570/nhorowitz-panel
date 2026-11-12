<?php

namespace App\Support;

use App\Events\DeployQueued;
use App\Models\Activity;
use App\Models\DaemonJob;
use App\Models\DaemonRelease;
use App\Models\DeploySource;
use App\Models\ImpersonationView;
use App\Models\Machine;
use App\Models\User;
use App\Support\Audit\AuditContext;
use App\Support\Deploys\AppDatabase;
use App\Support\GitHub\GitHubApp;
use Illuminate\Support\Str;
use RuntimeException;

class DaemonJobQueue
{
    public const array NEEDS_PLAN = ['deploy', 'release', 'rollback', 'service_exec', 'service_clone'];

    private const array READS = ['service_logs', 'service_inspect', 'discover', 'file_list', 'file_read', 'package_updates', 'disk_usage'];

    public function __construct(private readonly DaemonGatewayClient $gateway) {}

    public function unavailable(Machine $machine, ?string $kind = null): ?string
    {
        if (! config('daemon.commands_enabled')) {
            return 'Sending work to machines isn’t turned on for this panel.';
        }

        if ($kind !== null && ($reason = self::needsPlan($machine, $kind)) !== null) {
            return $reason;
        }

        if ($machine->uid === null || $machine->disconnected_at !== null || $machine->last_seen_at === null || $machine->last_seen_at->lt(now()->subSeconds(60))) {
            return "{$machine->name} is offline, so it can’t take work right now. Check the machine is on and the ServerOS daemon is running.";
        }

        return null;
    }

    public static function needsPlan(Machine $machine, string $kind): ?string
    {
        if (! in_array($kind, self::NEEDS_PLAN, true) || $machine->workspace->isActive()) {
            return null;
        }

        return $kind === 'service_exec'
            ? 'The console needs a plan. Choose one in Billing to run commands again.'
            : 'Deploys need a plan. Choose one in Billing to deploy again. Everything that’s already running keeps running.';
    }

    /**
     * @param  array<string, mixed>  $job
     *
     * @throws RuntimeException
     */
    public function deploy(Machine $machine, User $user, array $job): DaemonJob
    {
        $job['snapshot_before'] = $machine->snapshotsBeforeDeploy((string) $job['service']);
        $database = $job['snapshot_before'] ? AppDatabase::for($machine, (string) $job['service'], (array) ($job['env'] ?? [])) : null;
        $job['snapshot_also'] = $database === null ? [] : [$database['key']];

        $record = $this->queue($machine, $user, 'deploy', $job, $job['service'], 1800);
        event(new DeployQueued($machine, $record, (array) ($job['domains'] ?? [])));

        return $record;
    }

    /**
     * @param  array<string, mixed>  $job
     * @param  array{kind: string, job: array<string, mixed>, service: string|null}|null  $then
     *
     * @throws RuntimeException
     */
    public function queue(Machine $machine, User $user, string $kind, array $job, ?string $service, int $timeoutSecs = 300, ?array $then = null): DaemonJob
    {
        if (($reason = self::needsPlan($machine, $kind)) !== null) {
            throw new RuntimeException($reason);
        }

        $session = AuditContext::viewAsSession($user);
        $command = [
            'actor' => self::actor($user, $session),
            'confirmed' => true,
            'timeout_secs' => $timeoutSecs,
            'job' => $job,
        ];

        $record = DaemonJob::query()->create([
            'id' => (string) Str::uuid(),
            'machine_id' => $machine->id,
            'user_id' => $user->id,
            'impersonation_session_id' => $session,
            'kind' => $kind,
            'command' => $command,
            'service' => $service,
            'state' => 'queued',
            'log' => [],
            'mcp_call_id' => self::callId(),
            'then' => $then,
        ]);

        $this->send($machine, $record, $command);
        $this->recordActivity($machine, $user, $kind, $job, $service);

        return $record;
    }

    public function followUp(DaemonJob $job): ?DaemonJob
    {
        $then = $job->then;
        if ($then === null || $job->state !== 'succeeded') {
            return null;
        }
        $job->forceFill(['then' => null])->saveQuietly();

        $user = $job->user;
        if ($user === null) {
            return null;
        }

        $from = $job->mcp_call_id === null ? [] : ['via' => 'mcp', 'call' => $job->mcp_call_id];

        try {
            return AuditContext::within($from, fn () => $this->queue($job->machine, $user, $then['kind'], $then['job'], $then['service']));
        } catch (RuntimeException $e) {
            report($e);

            return null;
        }
    }

    /**
     * @param  array<string, mixed>  $job
     * @param  array<string, mixed>  $meta
     */
    private function recordActivity(Machine $machine, User $user, string $kind, array $job, ?string $service, array $meta = []): void
    {
        $action = match ($kind) {
            'deploy', 'release' => 'service.deploy',
            'rollback' => 'service.rollback',
            'service_action' => in_array($job['action'] ?? null, ['start', 'stop', 'restart', 'reload'], true) ? 'service.'.$job['action'] : null,
            'service_exec' => 'console.command',
            'adopt' => 'service.adopt',
            'service_remove' => 'service.remove',
            'service_repair' => 'service.repair',
            'service_clone' => 'service.clone',
            'file_write' => 'file.write',
            'file_delete' => 'file.delete',
            default => null,
        };
        if ($action === null) {
            if (in_array($kind, self::READS, true) && ! ($job['apply'] ?? false)) {
                AuditContext::nothingChanged();
            }
            if (in_array($kind, ['file_list', 'file_read'], true) && ($session = AuditContext::viewAsSession($user)) !== null) {
                ImpersonationView::query()->create([
                    'impersonation_session_id' => $session,
                    'route' => 'workspace.machines.files',
                    'parameters' => ['machine' => (string) $machine->id, 'path' => mb_substr((string) ($job['path'] ?? ''), 0, 500)],
                ]);
            }

            return;
        }

        if (str_starts_with($kind, 'file_')) {
            $bytes = isset($job['content_b64']) ? strlen((string) base64_decode((string) $job['content_b64'])) : null;
            Activity::record($machine->workspace_id, $action, (string) ($job['path'] ?? ''), $machine, $user, array_filter(['bytes' => $bytes], fn ($v) => $v !== null));

            return;
        }

        $name = $service;
        $found = null;
        foreach ($machine->inventory['services'] ?? [] as $candidate) {
            if (is_array($candidate) && ($candidate['key'] ?? null) === $service && is_string($candidate['name'] ?? null)) {
                $name = $candidate['name'];
                $found = $candidate;
                break;
            }
        }

        if ($kind === 'deploy' && preg_match('#/api/deploy-sources/(\w+)\.git$#', (string) ($job['repo'] ?? ''), $m) === 1
            && is_string($file = DeploySource::query()->where('token', $m[1])->value('filename'))) {
            $meta['upload'] = $file;
        }
        if ($kind === 'service_clone') {
            $meta += array_filter(['as' => $job['name'] ?? null, 'data' => (bool) ($job['copy_data'] ?? false)], fn ($v) => $v !== null);
        }
        if ($kind === 'rollback') {
            $meta['commit'] = substr((string) ($job['release'] ?? ''), 0, 7);
            $meta['data'] = ($job['restore'] ?? []) !== [];
        }
        if ($kind === 'release') {
            $meta['commit'] = substr((string) ($job['commit'] ?? ''), 0, 7);
        }
        if ($kind === 'service_exec') {
            $meta['command'] = mb_substr((string) ($job['command'] ?? ''), 0, 500);
        }
        if ($kind === 'service_remove' && ($job['delete_data'] ?? false)) {
            $meta['deleted_data'] = true;
        }
        if ($kind === 'service_remove' && $found !== null && ($found['origin'] ?? null) !== 'created') {
            $meta['adopted'] = true;
        }

        Activity::record($machine->workspace_id, $action, $name, $machine, $user, $meta);
    }

    /** @return array{kind: string, name: string, id: string} */
    /** @return array<string, string> */
    private static function actor(User $user, ?int $viewAs): array
    {
        $from = AuditContext::via();
        $workflow = ($from['via'] ?? null) === 'workflow';

        return array_filter([
            'kind' => 'user',
            'name' => $viewAs === null ? $user->email : AuditContext::SUPPORT,
            'id' => (string) $user->id,
            'via' => self::text($from['via'] ?? null),
            'client' => self::text($workflow ? ($from['workflow'] ?? null) : ($from['client'] ?? null)),
            'tool' => self::text($from['tool'] ?? null),
            'call' => self::text($workflow && isset($from['run']) ? 'run-'.$from['run'] : ($from['call'] ?? null)),
        ], fn (?string $value) => $value !== null && $value !== '');
    }

    private static function text(mixed $value): ?string
    {
        return is_scalar($value) ? mb_substr((string) $value, 0, 120) : null;
    }

    private static function callId(): ?string
    {
        $from = AuditContext::via();

        return in_array($from['via'] ?? null, ['mcp', 'api'], true) && is_string($from['call'] ?? null) ? $from['call'] : null;
    }

    public function retry(DaemonJob $job, User $user): DaemonJob
    {
        $command = $job->command ?? throw new RuntimeException('This deploy can’t be retried.');
        if (($reason = self::needsPlan($job->machine, $job->kind)) !== null) {
            throw new RuntimeException($reason);
        }
        $session = AuditContext::viewAsSession($user);
        $command['actor'] = self::actor($user, $session);

        $record = DaemonJob::query()->create([
            'id' => (string) Str::uuid(),
            'machine_id' => $job->machine_id,
            'user_id' => $user->id,
            'impersonation_session_id' => $session,
            'kind' => $job->kind,
            'command' => $command,
            'service' => $job->service,
            'state' => 'queued',
            'log' => [],
            'mcp_call_id' => self::callId(),
        ]);

        $this->send($job->machine, $record, $command);
        $this->recordActivity($job->machine, $user, $job->kind, $command['job'] ?? [], $job->service, ['retry' => true]);

        return $record;
    }

    private const array MAPS = ['files', 'env'];

    /**
     * @param  array<string, mixed>  $command
     * @return array<string, mixed>
     */
    private static function withMaps(array $command): array
    {
        foreach (self::MAPS as $field) {
            if (isset($command['job'][$field]) && is_array($command['job'][$field])) {
                $command['job'][$field] = (object) $command['job'][$field];
            }
        }

        return $command;
    }

    // Added as the job is sent, never stored: the database must never hold the token.
    /**
     * @param  array<string, mixed>  $command
     * @return array<string, mixed>
     */
    private function withRepoToken(Machine $machine, array $command): array
    {
        $repo = $command['job']['repo'] ?? null;
        if (($command['job']['type'] ?? null) !== 'deploy' || ! is_string($repo)
            || ! preg_match('#^https://github\.com/([A-Za-z0-9-]+)/([A-Za-z0-9._-]+?)(?:\.git)?/?$#', $repo, $m)) {
            return $command;
        }

        $token = app(GitHubApp::class)->tokenFor($machine->workspace, $m[1], $m[2]);
        if ($token === null) {
            return $command;
        }
        if (! self::canFetchPrivate($machine)) {
            throw new RuntimeException("{$machine->name}’s daemon is too old to deploy private repositories. Update it with “sudo serverosd update”, then deploy again.");
        }
        $command['job']['repo_token'] = $token;

        return $command;
    }

    public static function canFetchPrivate(Machine $machine): bool
    {
        return self::atLeast($machine, (string) config('daemon.repo_token_since'));
    }

    public static function canInspect(Machine $machine): bool
    {
        return self::atLeast($machine, (string) config('daemon.inspect_since'));
    }

    public static function canSkipLogs(Machine $machine): bool
    {
        return self::atLeast($machine, (string) config('daemon.log_skip_since'));
    }

    public static function canRunTunnel(Machine $machine): bool
    {
        return self::atLeast($machine, (string) config('daemon.tunnel_since'));
    }

    public static function canFollowPolicy(Machine $machine): bool
    {
        return self::atLeast($machine, (string) config('daemon.policy_since'));
    }

    public static function canMaskIps(Machine $machine): bool
    {
        return self::atLeast($machine, (string) config('daemon.log_ip_masking_since'));
    }

    public static function canRemoveSystemd(Machine $machine): bool
    {
        return self::atLeast($machine, (string) config('daemon.systemd_remove_since'));
    }

    public static function canProjectData(Machine $machine): bool
    {
        return self::atLeast($machine, (string) config('daemon.project_data_since'));
    }

    public static function canExecEnv(Machine $machine): bool
    {
        return self::atLeast($machine, (string) config('daemon.exec_env_since'));
    }

    public static function canClone(Machine $machine): bool
    {
        return self::atLeast($machine, (string) config('daemon.clone_since'));
    }

    public static function canInstallVersions(Machine $machine): bool
    {
        return self::atLeast($machine, (string) config('daemon.package_versions_since'));
    }

    public static function canAddPackages(Machine $machine): bool
    {
        return self::atLeast($machine, (string) config('daemon.package_add_since'));
    }

    public function rescan(Machine $machine, User $user): ?DaemonJob
    {
        if ($this->unavailable($machine) !== null) {
            return null;
        }

        $running = DaemonJob::query()
            ->where('machine_id', $machine->id)
            ->where('kind', 'discover')
            ->whereIn('state', ['queued', 'running'])
            ->where('created_at', '>', now()->subMinutes(2))
            ->latest()
            ->first();

        try {
            return $running ?? $this->queue($machine, $user, 'discover', ['type' => 'discover'], null, 120);
        } catch (RuntimeException) {
            return null;
        }
    }

    public static function canWildcard(Machine $machine): bool
    {
        return self::atLeast($machine, (string) config('daemon.wildcard_domains_since'));
    }

    public static function canScanDisk(Machine $machine): bool
    {
        return self::atLeast($machine, (string) config('daemon.disk_usage_since'));
    }

    public static function inspectReleased(): bool
    {
        $latest = DaemonRelease::latest('stable', 'amd64')?->version;

        return $latest !== null && DaemonRelease::compare($latest, (string) config('daemon.inspect_since')) >= 0;
    }

    private static function atLeast(Machine $machine, string $since): bool
    {
        $version = ltrim((string) $machine->daemon_version, 'v');
        if (str_ends_with($version, '-local')) {
            return true;
        }

        return preg_match('/^\d+\.\d+\.\d+/', $version) === 1
            && DaemonRelease::compare($version, $since) >= 0;
    }

    /**
     * @param  array<string, mixed>  $command
     */
    private function send(Machine $machine, DaemonJob $record, array $command): void
    {
        try {
            $this->gateway->dispatch($machine, $record->id, $this->withRepoToken($machine, self::withMaps($command)));
        } catch (RuntimeException $e) {
            $record->forceFill([
                'state' => 'refused',
                'error' => ['phase' => 'queue', 'message' => $e->getMessage()],
                'finished_at' => now(),
            ])->save();

            throw $e;
        }
    }
}

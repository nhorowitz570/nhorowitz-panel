<?php

namespace App\Actions\Machines;

use App\Models\DaemonEnrolment;
use App\Models\MachineGroup;
use App\Models\MachinePolicy;
use App\Models\User;
use App\Models\Workspace;
use App\Support\Audit\AuditContext;
use Illuminate\Support\Facades\DB;
use RuntimeException;

final class CreateJoinCommand
{
    /**
     * @param  array{provider?: string, region?: string, tier?: string, labels?: array<string, string>}  $metadata
     * @return array{command: string, token: string, enrolment: DaemonEnrolment}|null
     *
     * @throws RuntimeException
     */
    public function create(
        Workspace $workspace,
        User $user,
        int $minutes = 15,
        bool $replace = false,
        ?MachinePolicy $policy = null,
        ?MachineGroup $group = null,
        array $metadata = [],
    ): ?array {
        if (! config('daemon.install_ready')) {
            throw new RuntimeException('The daemon release is not published yet. Connect machine will be available after the release and gateway are deployed.');
        }

        $installer = config('daemon.install_url');
        $panel = config('daemon.panel_url');
        $releases = config('daemon.releases_url');
        if (! filter_var($installer, FILTER_VALIDATE_URL) || ! filter_var($panel, FILTER_VALIDATE_URL) || ! filter_var($releases, FILTER_VALIDATE_URL)) {
            throw new RuntimeException('The daemon installer is not configured.');
        }
        foreach ([$policy, $group] as $chosen) {
            if ($chosen !== null && $chosen->workspace_id !== $workspace->id) {
                throw new RuntimeException('That belongs to another workspace.');
            }
        }

        $created = DB::transaction(function () use ($workspace, $user, $minutes, $replace, $policy, $group, $metadata): ?array {
            $locked = Workspace::query()->lockForUpdate()->findOrFail($workspace->id);
            if ($replace) {
                DaemonEnrolment::query()
                    ->where('workspace_id', $locked->id)
                    ->where('user_id', $user->id)
                    ->whereNull('used_at')
                    ->where('expires_at', '>', now())
                    ->update(['expires_at' => now()]);
            }
            $unused = fn () => DaemonEnrolment::query()
                ->where('workspace_id', $locked->id)
                ->whereNull('used_at')
                ->where('expires_at', '>', now());
            $limit = $locked->machineLimit();
            $full = fn (): bool => $limit !== null && $locked->machines()->count() + $unused()->count() >= $limit;
            if ($full()) {
                $unused()->where('user_id', $user->id)->update(['expires_at' => now()]);
            }
            if ($full()) {
                return null;
            }

            $plain = bin2hex(random_bytes(32));
            $enrolment = DaemonEnrolment::query()->create([
                'workspace_id' => $locked->id,
                'user_id' => $user->id,
                'impersonation_session_id' => AuditContext::sessionId(),
                'token_hash' => hash('sha256', $plain),
                'expires_at' => now()->addMinutes($minutes),
                'machine_policy_id' => $policy?->id,
                'machine_group_id' => $group?->id,
                'metadata' => $metadata === [] ? null : $metadata,
            ]);

            return [$plain, $enrolment];
        });

        if ($created === null) {
            return null;
        }
        [$token, $enrolment] = $created;

        return [
            'command' => 'curl -fsSL '.escapeshellarg($installer).' | sudo bash -s -- --token='.escapeshellarg($token).' --panel='.escapeshellarg($panel).' --releases='.escapeshellarg($releases),
            'token' => $token,
            'enrolment' => $enrolment,
        ];
    }
}

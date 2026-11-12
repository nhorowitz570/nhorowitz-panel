<?php

namespace App\Support\Maintenance;

use App\Enums\WorkspacePermission;
use App\Models\Activity;
use App\Models\DaemonJob;
use App\Models\Machine;
use App\Models\MaintenanceWindow;
use App\Models\User;
use App\Support\Audit\AuditContext;
use App\Support\DaemonJobQueue;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Throwable;

class ScheduledMaintenance
{
    private const UPDATES_TIMEOUT = 1800;

    private const REBOOT_PATIENCE_MINUTES = 15;

    public function __construct(private readonly DaemonJobQueue $queue) {}

    public function schedule(Machine $machine, User $by, CarbonInterface $startsAt, int $minutes, ?string $reason, bool $public, string $action): MaintenanceWindow
    {
        $endsAt = $startsAt->copy()->addMinutes($minutes);
        $overlap = MaintenanceWindow::query()
            ->where('machine_id', $machine->id)
            ->whereIn('state', ['scheduled', 'active'])
            ->where('starts_at', '<', $endsAt)
            ->where('ends_at', '>', $startsAt)
            ->first();
        if ($overlap !== null) {
            throw ValidationException::withMessages(['starts_at' => 'That overlaps maintenance already planned on '.$machine->name.' ('.$overlap->starts_at->format('j M H:i').' UTC). Cancel that one first, or pick another time.']);
        }

        $window = MaintenanceWindow::query()->create([
            'workspace_id' => $machine->workspace_id,
            'machine_id' => $machine->id,
            'created_by' => $by->id,
            'starts_at' => $startsAt,
            'ends_at' => $endsAt,
            'reason' => filled($reason) ? trim((string) $reason) : null,
            'public' => $public,
            'action' => $action,
        ]);
        Activity::record($machine->workspace_id, 'machine.maintenance_scheduled', $machine->name, $machine, $by, [
            'starts_at' => $window->starts_at->toIso8601String(),
            'minutes' => $minutes,
            'reason' => $window->reason,
            'action' => $action,
        ]);

        return $window;
    }

    public function cancel(MaintenanceWindow $window, User $by): void
    {
        if (! in_array($window->state, ['scheduled', 'active'], true)) {
            AuditContext::nothingChanged();

            return;
        }
        $active = $window->state === 'active';
        $window->forceFill(['state' => 'cancelled', 'finished_at' => now(), 'phase' => null])->save();
        if ($active) {
            $this->endMachineMaintenance($window);
        }
        Activity::record($window->workspace_id, 'machine.maintenance_cancelled', $window->machine->name, $window->machine, $by, [
            'starts_at' => $window->starts_at->toIso8601String(),
            'active' => $active,
        ]);
    }

    public function tick(): int
    {
        $changed = 0;

        MaintenanceWindow::query()->where('state', 'scheduled')->where('starts_at', '<=', now())
            ->with(['machine.workspace', 'creator'])->get()
            ->each(function (MaintenanceWindow $window) use (&$changed) {
                $this->start($window);
                $changed++;
            });

        MaintenanceWindow::query()->where('state', 'active')
            ->with(['machine.workspace', 'creator'])->get()
            ->each(function (MaintenanceWindow $window) use (&$changed) {
                $changed += $this->progress($window) ? 1 : 0;
            });

        return $changed;
    }

    public function start(MaintenanceWindow $window): void
    {
        $machine = $window->machine;
        if ($machine->workspace_id !== $window->workspace_id) {
            $window->forceFill(['state' => 'cancelled', 'finished_at' => now(), 'outcome' => 'The machine moved to another workspace.'])->save();

            return;
        }
        if ($window->ends_at->isPast()) {
            $window->forceFill(['state' => 'done', 'started_at' => now(), 'finished_at' => now(), 'outcome' => 'Missed: its time had passed before it could start.'])->save();

            return;
        }

        DB::transaction(function () use ($window, $machine) {
            $until = $machine->inMaintenance() && $machine->maintenance_until->gt($window->ends_at) ? $machine->maintenance_until : $window->ends_at;
            $machine->forceFill([
                'maintenance_until' => $until,
                'maintenance_reason' => $window->reason ?? $machine->maintenance_reason,
                'maintenance_public' => $window->public,
            ])->save();
            $window->forceFill(['state' => 'active', 'started_at' => now()])->save();
            Activity::record($machine->workspace_id, 'machine.maintenance', $machine->name, $machine, $window->creator, [
                'minutes' => (int) ceil(now()->diffInMinutes($window->ends_at)),
                'reason' => $window->reason,
                'scheduled' => true,
            ]);
        });

        match ($window->action) {
            'reboot' => $this->runJob($window, 'reboot', ['type' => 'reboot'], 60, 'rebooting'),
            'updates' => $this->runJob($window, 'package_updates', ['type' => 'package_updates', 'apply' => true, 'security_only' => false], self::UPDATES_TIMEOUT, 'updating'),
            default => null,
        };
    }

    public function progress(MaintenanceWindow $window): bool
    {
        if ($window->ends_at->isPast()) {
            $this->finish($window, $window->outcome ?? ($window->phase === 'rebooting' ? 'Its time ran out before the machine was back.' : null), early: false);

            return true;
        }

        $job = $window->job_id ? DaemonJob::query()->find($window->job_id) : null;

        if ($window->phase === 'updating' && $job !== null) {
            if ($job->state === 'succeeded') {
                if ((bool) ($job->result['reboot_required'] ?? false)) {
                    $this->runJob($window, 'reboot', ['type' => 'reboot'], 60, 'rebooting');
                } else {
                    $this->finish($window, 'Updates installed. No restart was needed.', early: true);
                }

                return true;
            }
            if ($job->state === 'failed' || $job->isStale()) {
                $window->forceFill(['phase' => null, 'outcome' => 'The updates didn’t finish: '.(self::jobError($job) ?: 'the job stopped answering').'.'])->save();

                return true;
            }
        }

        if ($window->phase === 'rebooting' && $job !== null) {
            $machine = $window->machine;
            if ($job->state === 'failed') {
                $window->forceFill(['phase' => null, 'outcome' => 'The restart didn’t start: '.(self::jobError($job) ?: 'the daemon refused it').'.'])->save();

                return true;
            }
            if ($machine->isOnline() && $machine->connected_at !== null && $machine->connected_at->gt($job->created_at)) {
                $seconds = max(1, (int) $job->created_at->diffInSeconds($machine->connected_at));
                $this->finish($window, ($window->action === 'updates' ? 'Updates installed, and back after a restart of ' : 'Back after a restart of ').self::duration($seconds).'.', early: true);

                return true;
            }
            if ($job->created_at->lt(now()->subMinutes(self::REBOOT_PATIENCE_MINUTES)) && $window->outcome === null) {
                $window->forceFill(['outcome' => 'Not back yet, '.self::REBOOT_PATIENCE_MINUTES.' minutes after the restart. Check it in your provider’s console.'])->save();

                return true;
            }
        }

        return false;
    }

    /** @return array{startsAt: string, cpu: float, days: int}|null */
    public function quietTime(Machine $machine): ?array
    {
        $from = now()->subDays(14)->getTimestamp();
        $rows = DB::table('machine_samples')->where('machine_id', $machine->id)->where('ts', '>=', $from)->whereNotNull('cpu')->get(['ts', 'cpu']);
        if ($rows->isEmpty()) {
            return null;
        }
        $days = (int) ceil(($rows->max('ts') - $rows->min('ts')) / 86400);
        if ($days < 3) {
            return null;
        }

        $buckets = [];
        foreach ($rows as $row) {
            $at = now()->setTimestamp((int) $row->ts)->utc();
            $key = $at->dayOfWeekIso.'-'.$at->hour;
            $buckets[$key][] = (float) $row->cpu;
        }
        $averages = array_map(fn (array $cpus) => array_sum($cpus) / count($cpus), $buckets);
        asort($averages);

        $soonest = now()->utc()->addHour()->startOfHour();
        foreach (array_keys($averages) as $key) {
            [$dow, $hour] = array_map('intval', explode('-', $key));
            $candidate = $soonest->copy()->setTime($hour, 0);
            while ($candidate->dayOfWeekIso !== $dow || $candidate->lt($soonest)) {
                $candidate = $candidate->addDay()->setTime($hour, 0);
            }

            return ['startsAt' => $candidate->toIso8601String(), 'cpu' => round($averages[$key], 1), 'days' => $days];
        }

        return null;
    }

    /** @param  array<string, mixed>  $job */
    private function runJob(MaintenanceWindow $window, string $kind, array $job, int $timeout, string $phase): void
    {
        $machine = $window->machine;
        $user = $window->creator;
        $reason = match (true) {
            $machine->workspace_id !== $window->workspace_id => $machine->name.' moved to another workspace, so nothing was run.',
            $user === null || ! $window->workspace->allows($user, WorkspacePermission::ManageMachines) => 'Whoever planned it has left the workspace or can’t manage machines any more, so nothing was run.',
            ! $machine->isOnline() => $machine->name.' wasn’t reporting when it started, so nothing was run.',
            default => $this->queue->unavailable($machine),
        };
        if ($reason !== null) {
            $window->forceFill(['phase' => null, 'outcome' => $reason])->save();

            return;
        }

        try {
            $record = $this->queue->queue($machine, $user, $kind, $job, null, $timeout);
        } catch (Throwable $e) {
            report($e);
            $window->forceFill(['phase' => null, 'outcome' => 'Couldn’t start it: '.$e->getMessage()])->save();

            return;
        }
        $window->forceFill(['phase' => $phase, 'job_id' => $record->id])->save();
    }

    private function finish(MaintenanceWindow $window, ?string $outcome, bool $early): void
    {
        $window->forceFill(['state' => 'done', 'phase' => $early ? 'back' : $window->phase, 'finished_at' => now(), 'outcome' => $outcome])->save();
        if ($early) {
            $this->endMachineMaintenance($window);
            Activity::system($window->workspace_id, 'machine.maintenance_done', $window->machine->name, $window->machine, ['outcome' => $outcome]);
        }
    }

    private function endMachineMaintenance(MaintenanceWindow $window): void
    {
        $machine = $window->machine->fresh();
        if ($machine !== null && $machine->inMaintenance() && $machine->maintenance_until->lte($window->ends_at->copy()->addMinute())) {
            $machine->forceFill(['maintenance_until' => null, 'maintenance_reason' => null])->save();
        }
    }

    private static function jobError(DaemonJob $job): string
    {
        return is_array($job->error) ? rtrim(trim((string) ($job->error['message'] ?? '')), '.') : '';
    }

    public static function duration(int $seconds): string
    {
        if ($seconds < 90) {
            return $seconds.' seconds';
        }
        $minutes = (int) round($seconds / 60);

        return $minutes.' minutes';
    }
}

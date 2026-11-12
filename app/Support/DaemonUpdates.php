<?php

namespace App\Support;

use App\Models\DaemonRelease;
use App\Models\Machine;
use App\Support\Machines\DaemonUpdateNotices;
use App\Support\Machines\MachinePolicies;
use RuntimeException;

class DaemonUpdates
{
    public function __construct(private readonly DaemonGatewayClient $gateway, private readonly DaemonUpdateNotices $notices) {}

    /** @return array{offered: list<string>, failed: array<string, string>, waiting: list<string>} */
    public function push(?string $arch = null): array
    {
        [$offered, $failed] = [[], []];
        /** @var array<int, array{release: DaemonRelease, machines: list<Machine>}> $waiting */
        $waiting = [];

        foreach (Machine::query()->whereNotNull('uid')->whereNull('disconnected_at')->get() as $machine) {
            $machineArch = DaemonRelease::arch((string) ($machine->facts['arch'] ?? ''));
            if (! $machine->isOnline() || $machineArch === null || ($arch !== null && $machineArch !== $arch)) {
                continue;
            }
            $updates = MachinePolicies::for($machine)->updates;
            if ($updates !== 'automatic') {
                if ($updates === 'approve' && ($release = DaemonRelease::latest('stable', $machineArch)) !== null && self::newer($release->version, $machine->daemon_version)) {
                    $waiting[$machine->workspace_id] ??= ['release' => $release, 'machines' => []];
                    $waiting[$machine->workspace_id]['machines'][] = $machine;
                }

                continue;
            }
            $release = DaemonRelease::latest('stable', $machineArch);
            if ($release === null || ! self::newer($release->version, $machine->daemon_version)) {
                continue;
            }

            $candidate = $release->toCandidate();
            try {
                $this->gateway->control($machine, [
                    'type' => 'self_update',
                    'version' => $candidate['version'],
                    'url' => $candidate['url'],
                    'sha256' => $candidate['sha256'],
                    'signature' => $candidate['signature'],
                    'channel' => $candidate['channel'],
                    'min_from' => $candidate['min_from'],
                    'approved' => false,
                ]);
                $offered[] = $machine->name;
            } catch (RuntimeException $e) {
                $failed[$machine->name] = $e->getMessage();
            }
        }

        foreach ($waiting as $wait) {
            $this->notices->waiting($wait['machines'][0]->workspace, $wait['release'], $wait['machines']);
        }

        return [
            'offered' => $offered,
            'failed' => $failed,
            'waiting' => array_merge(...array_map(fn (array $wait) => array_map(fn (Machine $machine) => $machine->name, $wait['machines']), array_values($waiting))),
        ];
    }

    public static function available(Machine $machine): ?DaemonRelease
    {
        $arch = DaemonRelease::arch((string) ($machine->facts['arch'] ?? ''));
        if ($arch === null) {
            return null;
        }

        $policy = MachinePolicies::for($machine);
        if ($policy->updates === 'pinned' && $policy->pinned_version !== null) {
            $pinned = DaemonRelease::query()->where('version', $policy->pinned_version)->where('arch', $arch)->first();

            return $pinned !== null && ltrim($pinned->version, 'v') !== ltrim((string) $machine->daemon_version, 'v') ? $pinned : null;
        }

        $release = DaemonRelease::latest('stable', $arch);

        return $release !== null && self::newer($release->version, $machine->daemon_version) ? $release : null;
    }

    /** @throws RuntimeException */
    public function install(Machine $machine): DaemonRelease
    {
        $release = self::available($machine) ?? throw new RuntimeException('This machine already runs the newest release.');
        $candidate = $release->toCandidate();
        $this->gateway->control($machine, [
            'type' => 'self_update',
            'version' => $candidate['version'],
            'url' => $candidate['url'],
            'sha256' => $candidate['sha256'],
            'signature' => $candidate['signature'],
            'channel' => $candidate['channel'],
            'min_from' => $candidate['min_from'],
            'approved' => true,
        ]);

        return $release;
    }

    public static function newer(string $release, ?string $running): bool
    {
        return $running === null || $running === '' || self::compare($release, $running) > 0;
    }

    private static function compare(string $a, string $b): int
    {
        [$coreA, $preA] = array_pad(explode('-', ltrim($a, 'v'), 2), 2, null);
        [$coreB, $preB] = array_pad(explode('-', ltrim($b, 'v'), 2), 2, null);

        $partsA = array_map('intval', explode('.', (string) $coreA));
        $partsB = array_map('intval', explode('.', (string) $coreB));
        for ($i = 0; $i < max(count($partsA), count($partsB)); $i++) {
            if (($partsA[$i] ?? 0) !== ($partsB[$i] ?? 0)) {
                return ($partsA[$i] ?? 0) <=> ($partsB[$i] ?? 0);
            }
        }

        if ($preA === null || $preB === null) {
            return ($preA === null) <=> ($preB === null);
        }
        $idsA = explode('.', $preA);
        $idsB = explode('.', $preB);
        for ($i = 0; $i < max(count($idsA), count($idsB)); $i++) {
            if (! isset($idsA[$i]) || ! isset($idsB[$i])) {
                return isset($idsA[$i]) <=> isset($idsB[$i]);
            }
            $order = ctype_digit($idsA[$i]) && ctype_digit($idsB[$i]) ? (int) $idsA[$i] <=> (int) $idsB[$i] : strcmp($idsA[$i], $idsB[$i]);
            if ($order !== 0) {
                return $order <=> 0;
            }
        }

        return 0;
    }
}

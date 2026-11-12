<?php

namespace App\Support\Projects;

use App\Models\DaemonJob;
use App\Models\Machine;
use App\Models\Project;
use App\Models\ProjectLink;
use App\Models\ProjectMachine;
use App\Models\ProjectNode;
use App\Models\User;
use App\Support\DaemonJobQueue;
use Illuminate\Support\Collection;
use RuntimeException;

class ProjectNetwork
{
    public function __construct(
        private readonly DaemonJobQueue $queue,
        private readonly ProjectLinks $links,
    ) {}

    public function sync(Project $project, User $user): void
    {
        $machines = $project->nodes()
            ->whereHas('machine', fn ($query) => $query->where('workspace_id', $project->workspace_id))
            ->pluck('machine_id')->unique()->values();

        foreach ($project->members()->whereNotIn('machine_id', $machines)->with('machine')->get() as $gone) {
            $this->leave($gone, $user);
        }

        foreach ($machines as $machineId) {
            $this->member($project, (int) $machineId);
        }

        foreach ($project->members()->with(['machine', 'project'])->get() as $member) {
            $this->send($member, $user);
        }
    }

    public function teardown(Project $project, User $user): void
    {
        foreach ($project->members()->with(['machine', 'project'])->get() as $member) {
            $this->leave($member, $user);
        }
    }

    public function jobFinished(DaemonJob $job): void
    {
        $spec = $job->command['job'] ?? [];
        if (($spec['action'] ?? null) !== 'up') {
            return;
        }
        $project = Project::query()->where('uid', (string) ($spec['network'] ?? ''))->first();
        $member = $project?->members()->where('machine_id', $job->machine_id)->with(['machine', 'project'])->first();
        if ($member === null) {
            return;
        }

        if ($job->state !== 'succeeded') {
            $member->forceFill([
                'state' => 'failed',
                'error' => mb_substr((string) ($job->error['message'] ?? 'The machine couldn’t set up the private network.'), 0, 500),
            ])->save();

            return;
        }

        $key = $job->result['public_key'] ?? null;
        $changed = is_string($key) && $key !== $member->public_key;
        $member->forceFill([
            'state' => 'up',
            'error' => null,
            'public_key' => is_string($key) ? $key : $member->public_key,
        ])->save();

        $user = $job->user;
        if ($user === null) {
            return;
        }

        if ($changed) {
            foreach ($project->members()->whereKeyNot($member->id)->with(['machine', 'project'])->get() as $other) {
                $this->send($other, $user);
            }
        }

        $this->links->retry($project, $user);
    }

    /** @return array<string, mixed> */
    public function spec(ProjectMachine $member): array
    {
        $project = $member->project;
        $others = $project->members()->whereKeyNot($member->id)
            ->whereHas('machine', fn ($query) => $query->where('workspace_id', $project->workspace_id))
            ->with('machine')->get();

        return [
            'type' => 'network',
            'network' => $project->uid,
            'action' => 'up',
            'address' => $member->address().'/24',
            'listen_port' => $member->listen_port,
            'peers' => $others
                ->filter(fn (ProjectMachine $other) => filled($other->public_key))
                ->map(fn (ProjectMachine $other) => array_filter([
                    'public_key' => $other->public_key,
                    'address' => $project->prefix().'.'.$other->host.'/32',
                    'endpoint' => self::endpoint($other),
                ], fn ($value) => $value !== null))
                ->values()
                ->all(),
            'expose' => $this->exposed($member)->values()->all(),
        ];
    }

    /** @return Collection<int, array{port: int, target: string}> */
    private function exposed(ProjectMachine $member): Collection
    {
        $used = ProjectLink::query()
            ->where('project_id', $member->project_id)
            ->with('resource')
            ->get()
            ->map(fn (ProjectLink $link) => $link->resource);
        $copied = ProjectNode::query()
            ->where('project_id', $member->project_id)
            ->whereIn('id', ProjectNode::query()->where('project_id', $member->project_id)->whereNotNull('origin_id')->whereNotNull('data_state')->whereIn('template', ProjectData::COPYABLE)->select('origin_id'))
            ->get();

        return $used->concat($copied)
            ->filter(fn (ProjectNode $resource) => $resource->machine_id === $member->machine_id)
            ->unique('id')
            ->map(fn (ProjectNode $resource) => $this->links->port($resource))
            ->filter()
            ->unique()
            ->map(fn (int $port) => ['port' => $port, 'target' => "127.0.0.1:{$port}"]);
    }

    private function member(Project $project, int $machineId): ProjectMachine
    {
        $existing = $project->members()->where('machine_id', $machineId)->first();
        if ($existing !== null) {
            return $existing;
        }

        $hosts = $project->members()->pluck('host')->all();
        $host = 2;
        while (in_array($host, $hosts, true)) {
            $host++;
        }
        if ($host > 254) {
            throw new RuntimeException('A project can have up to 253 machines.');
        }

        $ports = ProjectMachine::query()->where('machine_id', $machineId)->pluck('listen_port')->all();
        $port = ProjectMachine::FIRST_PORT;
        while (in_array($port, $ports, true)) {
            $port++;
        }

        $member = new ProjectMachine;
        $member->forceFill([
            'project_id' => $project->id,
            'machine_id' => $machineId,
            'host' => $host,
            'listen_port' => $port,
            'state' => 'pending',
        ])->save();

        return $member;
    }

    private function send(ProjectMachine $member, User $user): void
    {
        $machine = $member->machine;
        if ($machine->workspace_id !== $member->project->workspace_id) {
            return;
        }
        if ($this->queue->unavailable($machine) !== null) {
            $member->forceFill(['state' => $member->public_key === null ? 'offline' : $member->state])->save();

            return;
        }

        try {
            $this->queue->queue($machine, $user, 'network', $this->spec($member), null, 300);
        } catch (RuntimeException $e) {
            $member->forceFill(['state' => 'failed', 'error' => mb_substr($e->getMessage(), 0, 500)])->save();
        }
    }

    public function release(Machine $machine, string $network, User $user): void
    {
        if ($this->queue->unavailable($machine) !== null) {
            return;
        }

        try {
            $this->queue->queue($machine, $user, 'network', [
                'type' => 'network',
                'network' => $network,
                'action' => 'down',
            ], null, 120);
        } catch (RuntimeException $e) {
            report($e);
        }
    }

    private function leave(ProjectMachine $member, User $user): void
    {
        $this->release($member->machine, $member->project->uid, $user);
        $member->delete();
    }

    private static function endpoint(ProjectMachine $member): ?string
    {
        $address = (string) ($member->machine->ip_address ?? '');
        if (! filter_var($address, FILTER_VALIDATE_IP) || in_array($address, ['127.0.0.1', '::1'], true)) {
            return null;
        }

        return (str_contains($address, ':') ? "[{$address}]" : $address).':'.$member->listen_port;
    }

    public static function isUp(Project $project, Machine $machine): bool
    {
        return $project->members()->where('machine_id', $machine->id)->where('state', 'up')->exists();
    }
}

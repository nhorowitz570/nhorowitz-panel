<?php

namespace App\Support\Projects;

use App\Mcp\Tools\Deploy\Deploys;
use App\Models\DaemonJob;
use App\Models\DeploySource;
use App\Models\Project;
use App\Models\ProjectLink;
use App\Models\ProjectNode;
use App\Models\User;
use App\Support\DaemonFacts;
use App\Support\DaemonJobQueue;
use RuntimeException;

class ProjectLinks
{
    public function __construct(
        private readonly DaemonJobQueue $queue,
        private readonly Connections $connections,
    ) {}

    public function connect(ProjectLink $link, User $user): void
    {
        $link->loadMissing(['app.machine', 'resource.machine', 'project']);
        $app = $link->app;
        $resource = $link->resource;
        if ($app->machine->workspace_id !== $link->project->workspace_id || $resource->machine->workspace_id !== $link->project->workspace_id) {
            $link->forceFill(['state' => 'failed', 'error' => 'One of its machines moved to another workspace.'])->save();

            return;
        }

        if ($this->lastDeploy($app) === null) {
            $link->forceFill(['state' => 'pending', 'error' => null])->save();

            return;
        }

        $resourceDeploy = $this->lastDeploy($resource);
        $template = $resource->template ?? ($resourceDeploy !== null ? $this->connections->templateOf($resourceDeploy) : null);
        if ($resourceDeploy === null || $template === null) {
            $link->forceFill(['state' => 'pending', 'error' => null])->save();

            return;
        }

        $host = $this->host($link->project, $app, $resource, $template);
        if ($host === null) {
            $link->forceFill(['state' => 'pending', 'error' => null])->save();

            return;
        }

        $set = $this->connections->env($template, (array) $resourceDeploy->command['job'], $host);
        $this->write($link, $app, $set, [], $user, array_keys($set));
    }

    public function disconnect(ProjectLink $link, User $user): void
    {
        $link->loadMissing(['app.machine']);
        $names = (array) ($link->env ?? []);
        if ($link->state === 'applied' && $names !== []) {
            try {
                $this->write($link, $link->app, [], $names, $user, []);
            } catch (RuntimeException $e) {
                report($e);
            }
        }
    }

    public function retry(Project $project, User $user): void
    {
        foreach ($project->links()->where('state', '!=', 'applied')->get() as $link) {
            $this->connect($link, $user);
        }
    }

    public function deployFinished(DaemonJob $job): void
    {
        if ($job->kind !== 'deploy' || $job->state !== 'succeeded' || ! is_string($job->service) || $job->user === null) {
            return;
        }

        $nodes = ProjectNode::query()
            ->where('machine_id', $job->machine_id)
            ->where('name', $job->service)
            ->get();

        foreach ($nodes as $node) {
            $column = $node->isApp() ? 'app_node_id' : 'resource_node_id';
            foreach (ProjectLink::query()->where($column, $node->id)->where('state', '!=', 'applied')->get() as $link) {
                $this->connect($link, $job->user);
            }
        }
    }

    public function port(ProjectNode $resource): ?int
    {
        $spec = Placement::latestDeploy($resource->machine, $resource->name)?->command['job'] ?? null;
        $port = $spec['port'] ?? $spec['env']['PORT'] ?? null;

        return is_numeric($port) ? (int) $port : null;
    }

    private function lastDeploy(ProjectNode $node): ?DaemonJob
    {
        return Deploys::lastDeploy($node->machine, $node->name);
    }

    private function host(Project $project, ProjectNode $app, ProjectNode $resource, string $template): ?string
    {
        if ($app->machine_id === $resource->machine_id && (Connections::TEMPLATES[$template]['bridge'] ?? false)) {
            return DaemonFacts::dockerBridge($resource->machine->facts ?? []);
        }

        $provider = $project->members()->where('machine_id', $resource->machine_id)->first();
        if ($provider === null || $provider->state !== 'up') {
            return null;
        }
        if (! ProjectNetwork::isUp($project, $app->machine)) {
            return null;
        }

        return $provider->setRelation('project', $project)->address();
    }

    /**
     * @param  array<string, string>  $set
     * @param  list<string>  $remove
     * @param  list<string>  $remember
     */
    private function write(ProjectLink $link, ProjectNode $app, array $set, array $remove, User $user, array $remember): void
    {
        $machine = $app->machine;
        if ($machine->workspace_id !== $link->project->workspace_id) {
            return;
        }
        $last = Placement::latestDeploy($machine, $app->name);
        if ($last === null || ! $last->isRetryable()) {
            $link->forceFill(['state' => 'failed', 'error' => "{$app->name} has no deploy to change yet. Deploy it, then connect it again."])->save();

            return;
        }

        $spec = $last->command['job'];
        $repo = (string) ($spec['repo'] ?? '');
        if (Deploys::fromUpload($repo) && ! (DeploySource::fromRepoUrl($repo)?->usable() ?? false)) {
            $link->forceFill(['state' => 'failed', 'error' => "The code uploaded for {$app->name}’s live version has been cleared. Upload it again, then connect it again."])->save();

            return;
        }

        $env = (array) ($spec['env'] ?? []);
        $next = array_diff_key([...$env, ...$set], array_flip($remove));
        if ($next == $env) {
            $link->forceFill(['state' => 'applied', 'error' => null, 'env' => $remember ?: $link->env])->save();

            return;
        }

        if (($reason = $this->queue->unavailable($machine, 'deploy')) !== null) {
            $link->forceFill(['state' => 'pending', 'error' => $reason])->save();

            return;
        }

        $spec['env'] = (object) $next;
        try {
            $this->queue->deploy($machine, $user, $spec);
        } catch (RuntimeException $e) {
            $link->forceFill(['state' => 'failed', 'error' => mb_substr($e->getMessage(), 0, 500)])->save();

            return;
        }
        $link->forceFill(['state' => 'applied', 'error' => null, 'env' => $remember])->save();
    }
}

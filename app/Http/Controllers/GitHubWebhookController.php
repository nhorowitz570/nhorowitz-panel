<?php

namespace App\Http\Controllers;

use App\Models\Activity;
use App\Models\GitHubInstallation;
use App\Models\Project;
use App\Models\Workflow;
use App\Support\GitHub\GitHubApp;
use App\Support\Projects\Previews;
use App\Support\Workflows\WorkflowEngine;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Throwable;

class GitHubWebhookController extends Controller
{
    public function __invoke(Request $request, WorkflowEngine $engine, Previews $previews): JsonResponse
    {
        if (! GitHubApp::signed($request->getContent(), $request->header('X-Hub-Signature-256'))) {
            return response()->json(['error' => 'Bad signature.'], 401);
        }
        if ($request->header('X-GitHub-Event') === 'pull_request') {
            return $this->pullRequest($request, $previews);
        }
        if ($request->header('X-GitHub-Event') !== 'push') {
            return response()->json(['ignored' => true]);
        }

        $installation = (int) $request->input('installation.id');
        $repo = (string) $request->input('repository.full_name');
        $ref = (string) $request->input('ref');
        $commit = (string) $request->input('after');
        if ($installation === 0 || $repo === '' || ! str_starts_with($ref, 'refs/heads/')
            || $request->boolean('deleted') || preg_match('/^[0-9a-f]{40}$/', $commit) !== 1) {
            return response()->json(['ignored' => true]);
        }
        $branch = substr($ref, strlen('refs/heads/'));
        $message = (string) str((string) $request->input('head_commit.message'))->before("\n")->limit(120);
        $data = [
            'repo' => $repo,
            'branch' => $branch,
            'commit' => $commit,
            'message' => $message,
            'pusher' => (string) $request->input('pusher.name'),
            'url' => (string) $request->input('head_commit.url'),
            'pushed_at' => self::pushedAt($request->input('repository.pushed_at')),
        ];

        $workspaces = self::workspaces($installation, (int) $request->input('repository.id'));
        $workflows = Workflow::query()
            ->with('workspace')
            ->whereIn('workspace_id', $workspaces)
            ->where('enabled', true)
            ->where('trigger', 'git.push')
            ->get()
            ->filter(function (Workflow $workflow) use ($repo, $branch) {
                $rules = $workflow->triggerRules();

                return strcasecmp(trim((string) ($rules['repo'] ?? '')), $repo) === 0
                    && (($rules['branch'] ?? '') ?: 'main') === $branch;
            });

        $started = 0;
        $skipped = 0;
        foreach ($workflows as $workflow) {
            $stale = $this->stale($workflow, $data);
            if ($stale !== null) {
                Activity::system($workflow->workspace, 'workflow.skipped', $workflow->name, null, [
                    'reason' => $stale, 'repo' => $repo, 'branch' => $branch, 'commit' => substr($commit, 0, 7),
                ]);
                $skipped++;

                continue;
            }
            $summary = "Push to {$branch} of {$repo}: ".substr($commit, 0, 7).($message !== '' ? " {$message}" : '');
            if ($engine->start($workflow, 'git.push', $summary, $data) !== null) {
                $started++;
            }
        }

        return response()->json(['started' => $started, 'skipped' => $skipped], 202);
    }

    /** @return list<int> */
    private static function workspaces(int $installation, int $repository): array
    {
        return array_values(GitHubInstallation::query()
            ->where('installation_id', $installation)
            ->get()
            ->filter(fn (GitHubInstallation $link) => $link->allows($repository))
            ->map(fn (GitHubInstallation $link) => $link->workspace_id)
            ->all());
    }

    /** @param  array{repo: string, branch: string, commit: string, pushed_at: int|null}  $push */
    private function stale(Workflow $workflow, array $push): ?string
    {
        $runs = $workflow->runs()->latest('id')->limit(100)->get(['id', 'trigger']);
        foreach ($runs as $run) {
            $seen = $run->trigger['data'] ?? [];
            if (($run->trigger['kind'] ?? null) !== 'git.push'
                || strcasecmp((string) ($seen['repo'] ?? ''), $push['repo']) !== 0
                || (string) ($seen['branch'] ?? '') !== $push['branch']) {
                continue;
            }
            $seenAt = isset($seen['pushed_at']) && is_int($seen['pushed_at']) ? $seen['pushed_at'] : null;
            if ($seenAt !== null && $push['pushed_at'] !== null && $seenAt > $push['pushed_at']) {
                return 'late';
            }
            if (strcasecmp((string) ($seen['commit'] ?? ''), $push['commit']) === 0
                && ($seenAt === null || $push['pushed_at'] === null || $seenAt >= $push['pushed_at'])) {
                return 'repeat';
            }
        }

        return null;
    }

    private static function pushedAt(mixed $value): ?int
    {
        if (is_int($value) || is_float($value) || (is_string($value) && ctype_digit($value))) {
            return (int) $value;
        }
        if (is_string($value) && $value !== '') {
            try {
                return CarbonImmutable::parse($value)->getTimestamp();
            } catch (Throwable) {
                return null;
            }
        }

        return null;
    }

    private function pullRequest(Request $request, Previews $previews): JsonResponse
    {
        $installation = (int) $request->input('installation.id');
        $repo = strtolower((string) $request->input('repository.full_name'));
        $action = (string) $request->input('action');
        $number = (int) $request->input('pull_request.number');
        $head = (string) $request->input('pull_request.head.sha');
        $fromFork = strtolower((string) $request->input('pull_request.head.repo.full_name')) !== $repo;
        if ($installation === 0 || $repo === '' || $number < 1 || preg_match('/^[0-9a-f]{40}$/', $head) !== 1
            || ! in_array($action, ['opened', 'reopened', 'synchronize', 'closed'], true)) {
            return response()->json(['ignored' => true]);
        }
        if ($fromFork && $action !== 'closed') {
            return response()->json(['ignored' => 'fork']);
        }

        $workspaces = self::workspaces($installation, (int) $request->input('repository.id'));
        $projects = Project::query()
            ->with('workspace')
            ->whereIn('workspace_id', $workspaces)
            ->whereNotNull('previews')
            ->get()
            ->filter(fn (Project $project) => strtolower(Previews::settings($project)['repo']) === $repo
                && ($action === 'closed' || Previews::settings($project)['enabled']));

        foreach ($projects as $project) {
            try {
                match ($action) {
                    'opened', 'reopened' => $previews->opened($project, $number, $head),
                    'synchronize' => $previews->updated($project, $number, $head),
                    'closed' => $previews->closed($project, $number),
                };
            } catch (Throwable $e) {
                report($e);
            }
        }

        return response()->json(['projects' => $projects->count()], 202);
    }
}

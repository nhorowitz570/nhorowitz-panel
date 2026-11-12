<?php

namespace App\Support\Workflows;

use App\Enums\WorkspacePermission;
use App\Models\DaemonJob;
use App\Models\WorkflowRun;
use App\Models\Workspace;
use App\Support\Ai\AiProviders;
use App\Support\Brand;
use App\Support\GitHub\GitHubApp;
use RuntimeException;

final class AgentSpec
{
    public const AGENTS = ['claude' => 'Claude Code', 'codex' => 'Codex'];

    public const MIN_MINUTES = 5;

    public const MAX_MINUTES = 60;

    public const DEFAULT_MINUTES = 20;

    public const MAX_PROMPT = 4000;

    public const SUMMARY = '::serveros-summary::';

    public const PR = '::serveros-pr::';

    public const ERROR = '::serveros-error::';

    public const NOTE = '::serveros-note::';

    public const MODEL = '::serveros-model::';

    public const STAT = '::serveros-stat::';

    public const CHANGES = '::serveros-changes::';

    public const CHANGE = '::serveros-change::';

    public const MAX_CHANGE_CHARS = 400_000;

    public const MAX_CHANGED_FILES = 300;

    public static function minutes(mixed $value): int
    {
        $minutes = (int) ($value ?: self::DEFAULT_MINUTES);

        return max(self::MIN_MINUTES, min(self::MAX_MINUTES, $minutes));
    }

    /** @param  array<string, mixed>  $rules */
    public static function problem(array $rules, ?string $event = null): ?string
    {
        $repo = trim((string) ($rules['repo'] ?? ''));
        $fromTrigger = $repo === WorkflowSteps::FROM_TRIGGER;

        return match (true) {
            $fromTrigger && $event !== 'deploy.finished' => 'Only a “deploy finishes” trigger has a repository to work in; enter one as owner/name.',
            ! isset(self::AGENTS[(string) (($rules['agent'] ?? '') ?: 'claude')]) => 'Choose Claude Code or Codex.',
            trim((string) ($rules['prompt'] ?? '')) === '' => 'Write what the agent should do.',
            $repo !== '' && ! $fromTrigger && ! self::templated($repo) && ReleaseSpec::github($repo) === null => 'Enter the repository, as owner/name.',
            $repo !== '' && ! self::templated((string) ($rules['branch'] ?? '')) && ! ReleaseSpec::validBranch((string) (($rules['branch'] ?? '') ?: 'main')) => 'That isn’t a branch name.',
            $repo === '' && (bool) ($rules['pull_request'] ?? false) => 'Choose a repository for the pull request.',
            ($rules['model'] ?? '') !== '' && preg_match('#^[A-Za-z0-9._:/\[\]-]{1,80}$#', (string) $rules['model']) !== 1 => 'That isn’t a model name, like opus or gpt-5-codex.',
            $repo === '' && trim((string) ($rules['setup'] ?? '').($rules['check'] ?? '')) !== '' => 'Setup and check commands run in the repository: choose one.',
            ($rules['minutes'] ?? '') !== '' && ((int) $rules['minutes'] < self::MIN_MINUTES || (int) $rules['minutes'] > self::MAX_MINUTES) => 'Give it between '.self::MIN_MINUTES.' and '.self::MAX_MINUTES.' minutes.',
            default => null,
        };
    }

    public static function templated(string $value): bool
    {
        return str_contains($value, '{{');
    }

    /** @param  array<string, mixed>  $rules */
    public static function branch(WorkflowRun $run, array $rules): string
    {
        $branch = trim(WorkflowText::render((string) ($rules['branch'] ?? ''), $run));

        return $branch === '' ? 'main' : $branch;
    }

    /**
     * @param  array<string, mixed>  $rules
     * @return array<string, mixed>
     *
     * @throws RuntimeException
     */
    public static function job(Workspace $workspace, WorkflowRun $run, string $node, string $service, array $rules): array
    {
        $agent = (string) (($rules['agent'] ?? '') ?: 'claude');
        if (trim((string) ($rules['repo'] ?? '')) !== '' && self::repository($run, $rules) === null) {
            throw new RuntimeException(trim((string) $rules['repo']) === WorkflowSteps::FROM_TRIGGER
                ? 'The deployed app isn’t built from a GitHub repository, so there’s nothing to work in.'
                : 'The repository the trigger gave isn’t owner/name on GitHub.');
        }
        if (! ReleaseSpec::validBranch(self::branch($run, $rules))) {
            throw new RuntimeException('The branch the trigger gave isn’t a branch name.');
        }
        $minutes = self::minutes($rules['minutes'] ?? null);
        $env = [
            'SERVEROS_AGENT_SCRIPT' => self::script(),
            'SERVEROS_AGENT_KIND' => $agent,
            'SERVEROS_AGENT_PROMPT' => self::prompt($run, $rules),
            'SERVEROS_AGENT_MINUTES' => (string) $minutes,
            'SERVEROS_AGENT_RUN' => mb_substr("{$run->workflow->name}, run #{$run->id}", 0, 120),
            'SERVEROS_AGENT_TRIGGER' => mb_substr((string) ($run->trigger['summary'] ?? ''), 0, 200),
            'SERVEROS_AGENT_ASK' => mb_substr(trim(WorkflowText::render((string) ($rules['prompt'] ?? ''), $run)), 0, 3000),
            'SERVEROS_AGENT_RUN_URL' => 'https://'.$run->workflow->workspace->host()."/workflows/{$run->workflow->id}?run={$run->id}",
        ];
        foreach (['model' => 'SERVEROS_AGENT_MODEL', 'setup' => 'SERVEROS_AGENT_SETUP', 'check' => 'SERVEROS_AGENT_CHECK'] as $rule => $name) {
            $value = trim((string) ($rules[$rule] ?? ''));
            if ($value !== '') {
                $env[$name] = $value;
            }
        }

        $own = app(AiProviders::class)->key($workspace, $agent === 'codex' ? 'openai' : 'anthropic');
        if ($own !== null) {
            $runAs = $run->workflow->runAs;
            if ($runAs !== null && $workspace->allows($runAs, WorkspacePermission::ManageSettings)) {
                $env[$agent === 'codex' ? 'OPENAI_API_KEY' : 'ANTHROPIC_API_KEY'] = $own->key;
            } else {
                $env['SERVEROS_AGENT_KEY_WITHHELD'] = '1';
            }
        }

        $repo = self::repository($run, $rules);
        if ($repo !== null) {
            [$owner, $name] = $repo;
            $env['SERVEROS_AGENT_REPO'] = "{$owner}/{$name}";
            $env['SERVEROS_AGENT_BRANCH'] = self::branch($run, $rules);
            if ((bool) ($rules['pull_request'] ?? false)) {
                $env['SERVEROS_AGENT_PR_BRANCH'] = self::pullRequestBranch($run, $node);
                $env['SERVEROS_AGENT_TITLE'] = self::title($run, $rules);
            }
            $token = app(GitHubApp::class)->tokenFor($workspace, $owner, $name);
            if ($token !== null) {
                $env['SERVEROS_GH_TOKEN'] = $token;
                if (isset($env['SERVEROS_AGENT_PR_BRANCH'])) {
                    $env['SERVEROS_AGENT_HANDOFF'] = '1';
                }
            }
        }

        return [
            'type' => 'service_exec',
            'service' => $service,
            'command' => 'exec sh -c "$SERVEROS_AGENT_SCRIPT" serveros-agent',
            'timeout_secs' => $minutes * 60,
            'env' => $env,
        ];
    }

    /** @param  array<string, mixed>  $rules */
    public static function prompt(WorkflowRun $run, array $rules): string
    {
        $asked = trim(WorkflowText::render((string) ($rules['prompt'] ?? ''), $run));
        $data = $run->trigger['data'] ?? [];
        $details = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: '{}';
        $repo = self::repository($run, $rules);
        $branch = self::branch($run, $rules);
        $job = self::triggerJob($run);

        $lines = [
            $asked,
            '',
            '---',
            'Context from ServerOS. A workflow started this run; nobody is watching it live, so don’t ask questions: decide, and say what you assumed.',
            '- Workflow: '.$run->workflow->name,
            '- Started by: '.($run->trigger['summary'] ?? 'someone'),
        ];
        if (filled($data['machine'] ?? null)) {
            $lines[] = '- Machine: '.$data['machine'];
        }
        if (filled($data['service'] ?? null)) {
            $lines[] = '- Service: '.$data['service'];
        }
        if ($data !== []) {
            $lines[] = '- What the trigger carried: '.mb_substr($details, 0, 3000);
        }
        if ($job !== null) {
            $error = is_array($job->error) ? trim((string) ($job->error['message'] ?? '')) : '';
            $tail = array_slice(array_values(array_filter($job->log ?? [], 'is_string')), -80);
            $lines[] = '';
            $lines[] = "The {$job->kind} job the trigger was about ended {$job->state}".($error !== '' ? ": {$error}" : '.');
            if ($tail !== []) {
                $lines[] = 'The end of its log:';
                $lines[] = '```';
                $lines[] = mb_substr(implode("\n", $tail), -6000);
                $lines[] = '```';
            }
        }
        $lines[] = '';
        if ($repo === null) {
            $lines[] = 'There is no repository checked out. Work in the current folder if you need files.';
        } elseif ((bool) ($rules['pull_request'] ?? false)) {
            $lines[] = "You’re in a fresh checkout of {$repo[0]}/{$repo[1]} at {$branch}. Make your change here; you can commit it or leave it uncommitted. Don’t push: once you finish, ServerOS pushes a branch and opens a pull request with your summary as its description. Keep the change small and focused on the task.";
        } else {
            $lines[] = "You’re in a fresh checkout of {$repo[0]}/{$repo[1]} at {$branch}, to read. Nothing you change here is kept.";
        }
        $check = trim((string) ($rules['check'] ?? ''));
        if ($repo !== null && $check !== '') {
            $lines[] = "Before you finish, run `{$check}` and make it pass. ServerOS runs it again after you, and opens no pull request if it fails.";
        }
        $lines[] = 'Finish with a short summary for the team, in plain words and markdown, without a title heading: what you found, what you did, and anything a person should check. It becomes the pull request’s description, so leave out that you didn’t commit or push.';

        return mb_substr(implode("\n", $lines), 0, self::MAX_PROMPT + 12000);
    }

    /**
     * @param  array<string, mixed>  $rules
     * @return array{0: string, 1: string}|null
     */
    public static function repository(WorkflowRun $run, array $rules): ?array
    {
        $repo = trim((string) ($rules['repo'] ?? ''));
        if ($repo === WorkflowSteps::FROM_TRIGGER) {
            $spec = self::triggerJob($run)?->command['job'] ?? [];

            return is_array($spec) && is_string($spec['repo'] ?? null) ? ReleaseSpec::github($spec['repo']) : null;
        }

        if (self::templated($repo)) {
            $repo = trim(WorkflowText::render($repo, $run));
        }

        return $repo === '' ? null : ReleaseSpec::github($repo);
    }

    public static function triggerJob(WorkflowRun $run): ?DaemonJob
    {
        $id = $run->trigger['data']['job_id'] ?? null;
        if (! is_string($id) || $id === '') {
            return null;
        }

        return DaemonJob::query()
            ->whereKey($id)
            ->whereHas('machine', fn ($q) => $q->where('workspace_id', $run->workflow->workspace_id))
            ->first();
    }

    public static function pullRequestBranch(WorkflowRun $run, string $node): string
    {
        return "serveros/agent-{$run->id}-".substr(preg_replace('/[^a-z0-9-]/', '', strtolower($node)) ?: 'run', 0, 24);
    }

    /** @param  array<string, mixed>  $rules */
    public static function title(WorkflowRun $run, array $rules): string
    {
        $title = trim(preg_replace('/\s+/', ' ', WorkflowText::render((string) ($rules['pr_title'] ?? ''), $run)) ?? '');

        return mb_substr($title !== '' ? $title : "{$run->workflow->name}: changes from the agent", 0, 120);
    }

    /** @return array{summary: string, pr: string|null, error: string|null, note: string|null, model: string|null, stat: string|null, changes: bool} */
    public static function outcome(DaemonJob $job): array
    {
        $log = array_values(array_filter($job->log ?? [], 'is_string'));
        $pr = $error = $note = $model = $stat = null;
        $summaryAt = null;
        $changes = false;
        foreach ($log as $i => $line) {
            $line = rtrim($line);
            if (str_starts_with($line, self::PR) && preg_match('#(https://github\.com/\S+/pull/\d+)#', $line, $m)) {
                $pr = $m[1];
            } elseif (str_starts_with($line, self::CHANGES)) {
                $changes = true;
            } elseif (str_starts_with($line, self::STAT)) {
                $stat = mb_substr(trim(substr($line, strlen(self::STAT))), 0, 120) ?: null;
            } elseif (str_starts_with($line, self::ERROR)) {
                $error = trim(substr($line, strlen(self::ERROR)));
            } elseif (str_starts_with($line, self::MODEL)) {
                $model = mb_substr(trim(substr($line, strlen(self::MODEL))), 0, 80) ?: null;
            } elseif (str_starts_with($line, self::NOTE)) {
                $note = trim(substr($line, strlen(self::NOTE)));
            } elseif ($line === self::SUMMARY) {
                $summaryAt = $i;
            }
        }
        $summary = $summaryAt === null ? '' : trim(implode("\n", array_filter(array_slice($log, $summaryAt + 1), fn (string $l) => ! str_starts_with($l, '::serveros-'))));

        return ['summary' => mb_substr($summary, 0, 6000), 'pr' => $pr, 'error' => $error, 'note' => $note, 'model' => $model, 'stat' => $stat, 'changes' => $changes];
    }

    /**
     * @return array{base: string, files: list<array{path: string, mode: string, content: string|null}>}
     *
     * @throws RuntimeException
     */
    public static function changes(DaemonJob $job): array
    {
        $log = array_values(array_filter($job->log ?? [], 'is_string'));
        $header = null;
        $chunks = [];
        foreach ($log as $line) {
            $line = rtrim($line);
            if (str_starts_with($line, self::CHANGES)) {
                $header = preg_split('/\s+/', trim(substr($line, strlen(self::CHANGES)))) ?: [];
                $chunks = [];
            } elseif ($header !== null && str_starts_with($line, self::CHANGE)) {
                $chunks[] = trim(substr($line, strlen(self::CHANGE)));
            }
        }
        $payload = implode('', $chunks);
        if ($header === null || count($header) !== 2 || (int) $header[1] !== count($chunks) || ! hash_equals((string) $header[0], hash('sha256', $payload))) {
            throw new RuntimeException('The agent’s change didn’t come back from the machine whole, so no pull request was opened.');
        }
        if (strlen($payload) > self::MAX_CHANGE_CHARS) {
            throw new RuntimeException('The agent’s change is too big to send back, so no pull request was opened.');
        }
        $raw = base64_decode($payload, true);
        $json = $raw === false ? false : @gzdecode($raw, 64 * 1024 * 1024);
        $data = is_string($json) ? json_decode($json, true) : null;
        $base = $data['base'] ?? null;
        if (! is_array($data) || ! is_string($base) || preg_match('/^[0-9a-f]{40}$/', $base) !== 1 || ! is_array($data['files'] ?? null) || ! array_is_list($data['files'])) {
            throw new RuntimeException('The agent’s change came back in a form ServerOS can’t read, so no pull request was opened.');
        }
        if (count($data['files']) > self::MAX_CHANGED_FILES) {
            throw new RuntimeException('The agent changed more than '.self::MAX_CHANGED_FILES.' files, so no pull request was opened.');
        }

        $files = [];
        foreach ($data['files'] as $file) {
            $path = is_array($file) ? ($file['path'] ?? null) : null;
            $mode = is_array($file) ? ($file['mode'] ?? null) : null;
            $content = is_array($file) ? ($file['content'] ?? null) : null;
            $parts = is_string($path) ? explode('/', $path) : [];
            if (! is_string($path) || $path === '' || strlen($path) > 4096 || str_contains($path, "\0")
                || array_intersect($parts, ['', '.', '..']) !== [] || in_array('.git', array_map('strtolower', $parts), true)
                || ! in_array($mode, ['100644', '100755', '120000'], true)
                || ($content !== null && (! is_string($content) || base64_decode($content, true) === false))) {
                throw new RuntimeException('The agent’s change came back in a form ServerOS can’t read, so no pull request was opened.');
            }
            $files[] = ['path' => $path, 'mode' => $mode, 'content' => $content];
        }

        return ['base' => $base, 'files' => $files];
    }

    /**
     * @param  array<string, mixed>  $rules
     * @param  array{summary: string, model: string|null, stat: string|null}  $outcome
     */
    public static function pullRequestBody(WorkflowRun $run, array $rules, array $outcome): string
    {
        $esc = fn (?string $v): string => htmlspecialchars((string) $v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $agent = self::AGENTS[(string) (($rules['agent'] ?? '') ?: 'claude')] ?? 'The agent';
        $model = $outcome['model'] ?? 'its default model';
        $url = 'https://'.$run->workflow->workspace->host()."/workflows/{$run->workflow->id}?run={$run->id}";
        $name = mb_substr("{$run->workflow->name}, run #{$run->id}", 0, 120);
        $trigger = mb_substr((string) ($run->trigger['summary'] ?? ''), 0, 200);
        $check = trim((string) ($rules['check'] ?? ''));
        $ask = mb_substr(trim(WorkflowText::render((string) ($rules['prompt'] ?? ''), $run)), 0, 3000);
        $rows = array_filter([
            ['Agent', "{$esc($agent)} &nbsp;<code>{$esc($model)}</code>"],
            ['Workflow', '<a href="'.$esc($url).'">'.$esc($name).'</a>'],
            $trigger !== '' ? ['Started by', $esc($trigger)] : null,
            $check !== '' ? ['Check', "Passed &nbsp;<code>{$esc($check)}</code>"] : null,
            filled($outcome['stat'] ?? null) ? ['Changes', $esc($outcome['stat'])] : null,
        ]);
        $summary = mb_substr(trim($outcome['summary']), 0, 50000);

        return implode("\n", [
            "<b>ServerOS</b> &nbsp;<code>coding agent</code> &nbsp;·&nbsp; {$esc($agent)} &nbsp;<code>{$esc($model)}</code>",
            '',
            $summary !== '' ? $summary : '_The agent didn’t write a summary._',
            '',
            "<details><summary>Run details</summary>\n\n<table>".implode('', array_map(fn (array $r) => "<tr><td><b>{$r[0]}</b></td><td>{$r[1]}</td></tr>", $rows))."</table>\n\n"
                .($ask !== '' ? "<b>What it was asked</b>\n\n~~~text\n".str_replace('~~~', '~ ~ ~', $ask)."\n~~~\n" : '')."\n</details>",
            '',
            '<sub>Made on your own machine by <a href="https://serveros.com">ServerOS</a>. Nothing is merged without you. · <a href="'.$esc($url).'">Open the run</a></sub>',
        ]);
    }

    /**
     * @param  array<string, mixed>  $rules
     * @param  array{model: string|null}  $outcome
     */
    public static function commitMessage(WorkflowRun $run, array $rules, array $outcome): string
    {
        $kind = (string) (($rules['agent'] ?? '') ?: 'claude');
        $agent = self::AGENTS[$kind] ?? 'The agent';
        $coauthor = $kind === 'codex' ? 'Codex <codex@openai.com>' : 'Claude <noreply@anthropic.com>';
        $model = $outcome['model'] ?? 'its default model';

        return self::title($run, $rules)."\n\nMade by {$agent} ({$model}) in a ServerOS workflow: ".mb_substr("{$run->workflow->name}, run #{$run->id}", 0, 120).".\n\nCo-Authored-By: {$coauthor}";
    }

    public static function script(): string
    {
        return strtr(self::SCRIPT, [
            '__SERVEROS_AGENT_EMAIL__' => Brand::agentEmail(),
            '__SERVEROS_AGENT_MAX_CHANGE__' => (string) self::MAX_CHANGE_CHARS,
            '__SERVEROS_AGENT_MAX_FILES__' => (string) self::MAX_CHANGED_FILES,
        ]);
    }

    private const string SCRIPT = <<<'SH'
set -u
fail() { echo "::serveros-error:: $1"; exit 1; }
note() { echo "::serveros-note:: $1"; }

work=$(mktemp -d /tmp/serveros-agent.XXXXXX) || fail "Couldn't make a folder to work in."
trap 'rm -rf "$work"' EXIT
helper='!f() { test "$1" = get || exit 0; ok=; while IFS= read -r l; do [ "$l" = host=github.com ] && ok=1; done; test -n "$ok" && test -n "$GIT_TOKEN" && echo username=x-access-token && echo "password=$GIT_TOKEN"; }; f'
cd "$work" || fail "Couldn't open the work folder."

if [ -n "${SERVEROS_AGENT_REPO:-}" ]; then
  command -v git >/dev/null 2>&1 || fail "git isn't installed in this container. Use the Coding agent template, or install git."
  echo "Cloning $SERVEROS_AGENT_REPO at $SERVEROS_AGENT_BRANCH"
  GIT_TOKEN="${SERVEROS_GH_TOKEN:-${GH_TOKEN:-}}" git -c credential.helper= -c "credential.helper=$helper" clone -q --depth 50 --branch "$SERVEROS_AGENT_BRANCH" "https://github.com/$SERVEROS_AGENT_REPO.git" repo \
    || fail "Couldn't clone $SERVEROS_AGENT_REPO at $SERVEROS_AGENT_BRANCH. If it's private, connect it with ServerOS's GitHub App, or set GH_TOKEN on the agent's service."
  cd repo || fail "Couldn't open the checkout."
fi

# When ServerOS opens the pull request, no token that can write to the repository is ever sent here.
run_agent() {
  env -u GIT_TOKEN -u SERVEROS_GH_TOKEN -u GH_TOKEN -u GITHUB_TOKEN -u SERVEROS_AGENT_SCRIPT IS_SANDBOX=1 "$@"
}
safe_git() {
  env -u GIT_TOKEN -u SERVEROS_GH_TOKEN -u GH_TOKEN -u GITHUB_TOKEN GIT_CONFIG_NOSYSTEM=1 git -c core.hooksPath=/dev/null -c core.fsmonitor=false "$@"
}
keyhelp() {
  if [ -n "${SERVEROS_AGENT_KEY_WITHHELD:-}" ]; then
    echo "The workspace's $1 key only goes to workflows last saved by an owner or admin. Ask one to save this workflow, or set $2 on the agent's service."
  else
    echo "Connect an $1 key in Settings › AI and MCP, or set $2 on the agent's service."
  fi
}

if [ -n "${SERVEROS_AGENT_SETUP:-}" ]; then
  echo "Setting up: $SERVEROS_AGENT_SETUP"
  run_agent sh -c "$SERVEROS_AGENT_SETUP" >"$work/setup.txt" 2>&1 || { tail -n 20 "$work/setup.txt"; fail "The setup command failed, so the agent didn't start."; }
fi

case "${SERVEROS_AGENT_KIND:-claude}" in
  claude)
    command -v claude >/dev/null 2>&1 || fail "Claude Code isn't installed in this container. Deploy the Coding agent template, or run: npm install -g @anthropic-ai/claude-code"
    [ -n "${ANTHROPIC_API_KEY:-}${CLAUDE_CODE_OAUTH_TOKEN:-}" ] || fail "$(keyhelp Anthropic ANTHROPIC_API_KEY)"
    echo "Running Claude Code for up to ${SERVEROS_AGENT_MINUTES:-20} minutes"
    agent_name="Claude Code"; coauthor="Claude <noreply@anthropic.com>"
    set -- claude -p --dangerously-skip-permissions --output-format json
    [ -n "${SERVEROS_AGENT_MODEL:-}" ] && set -- "$@" --model "$SERVEROS_AGENT_MODEL"
    run_agent "$@" "$SERVEROS_AGENT_PROMPT" >"$work/out.json" 2>"$work/err.txt"
    code=$?
    # Its answer, and the model that did the work (the one with the most output).
    model=$(node -e '
const fs = require("fs"), [src, out] = process.argv.slice(1);
let j = {}; try { j = JSON.parse(fs.readFileSync(src, "utf8")); } catch { process.exit(0); }
fs.writeFileSync(out, String(j.result ?? ""));
const used = Object.entries(j.modelUsage || {}).sort((a, b) => (b[1].outputTokens || 0) - (a[1].outputTokens || 0));
if (used[0]) console.log(used[0][0]);
' "$work/out.json" "$work/out.txt" 2>/dev/null)
    [ -s "$work/out.txt" ] || cp "$work/out.json" "$work/out.txt" 2>/dev/null
    ;;
  codex)
    command -v codex >/dev/null 2>&1 || fail "Codex isn't installed in this container. Deploy the Coding agent template, or run: npm install -g @openai/codex"
    [ -n "${OPENAI_API_KEY:-}" ] || fail "$(keyhelp OpenAI OPENAI_API_KEY)"
    echo "Running Codex for up to ${SERVEROS_AGENT_MINUTES:-20} minutes"
    agent_name="Codex"; coauthor="Codex <codex@openai.com>"
    model="${SERVEROS_AGENT_MODEL:-}"
    set -- codex exec --dangerously-bypass-approvals-and-sandbox --skip-git-repo-check --output-last-message "$work/out.txt"
    [ -n "${SERVEROS_AGENT_MODEL:-}" ] && set -- "$@" -m "$SERVEROS_AGENT_MODEL"
    run_agent "$@" "$SERVEROS_AGENT_PROMPT" >"$work/err.txt" 2>&1
    code=$?
    ;;
  *) fail "Unknown agent: ${SERVEROS_AGENT_KIND}" ;;
esac
[ -n "${model:-}" ] || model="${SERVEROS_AGENT_MODEL:-its default model}"
echo "::serveros-model:: $model"
if [ "$code" -ne 0 ]; then
  tail -n 20 "$work/err.txt" 2>/dev/null
  fail "The agent stopped with exit code $code."
fi

if [ -n "${SERVEROS_AGENT_PR_BRANCH:-}" ]; then
  ahead() { safe_git rev-list --count "origin/$SERVEROS_AGENT_BRANCH..HEAD" 2>/dev/null || echo 0; }
  if [ -n "$(safe_git status --porcelain)" ]; then
    safe_git add -A && safe_git -c user.name="${SERVEROS_AGENT_AUTHOR_NAME:-ServerOS agent}" -c user.email="${SERVEROS_AGENT_AUTHOR_EMAIL:-__SERVEROS_AGENT_EMAIL__}" commit -q --no-verify -m "$SERVEROS_AGENT_TITLE" -m "Made by $agent_name ($model) in a ServerOS workflow: ${SERVEROS_AGENT_RUN:-a workflow run}." -m "Co-Authored-By: $coauthor" \
      || fail "Couldn't commit the agent's changes."
  fi
  if [ -n "${SERVEROS_AGENT_CHECK:-}" ] && { [ -n "$(safe_git status --porcelain)" ] || [ "$(ahead)" -gt 0 ]; }; then
    echo "Checking: $SERVEROS_AGENT_CHECK"
    run_agent sh -c "$SERVEROS_AGENT_CHECK" >"$work/check.txt" 2>&1 || {
      tail -n 30 "$work/check.txt"
      echo "::serveros-summary::"; head -c 6000 "$work/out.txt" | grep -v '^::serveros-' | head -n 200; echo
      fail "The check failed after the agent's change, so no pull request was opened."
    }
    checked="$SERVEROS_AGENT_CHECK"
  fi
  if [ "$(ahead)" -gt 0 ]; then
    stat=$(safe_git diff --shortstat "origin/$SERVEROS_AGENT_BRANCH..HEAD" 2>/dev/null | sed 's/^ *//')
    if [ -n "${SERVEROS_AGENT_HANDOFF:-}" ]; then
      command -v node >/dev/null 2>&1 || fail "Node isn't here to send the agent's change back to ServerOS."
      base=$(safe_git rev-parse "origin/$SERVEROS_AGENT_BRANCH^{commit}") || fail "Couldn't find $SERVEROS_AGENT_BRANCH in the checkout."
      env -u GIT_TOKEN -u SERVEROS_GH_TOKEN -u GH_TOKEN -u GITHUB_TOKEN node -e '
const { execFileSync } = require("child_process");
const zlib = require("zlib"), crypto = require("crypto");
const env = { ...process.env, GIT_CONFIG_NOSYSTEM: "1" };
const git = (...a) => execFileSync("git", ["--literal-pathspecs", "-c", "core.hooksPath=/dev/null", "-c", "core.fsmonitor=false", ...a], { env, maxBuffer: 1 << 30 });
const [base, max, most] = process.argv.slice(1);
const parts = git("diff", "--name-status", "--no-renames", "-z", base, "HEAD").toString("utf8").split("\0");
const entry = (rev, path) => { const [meta] = git("ls-tree", "-z", rev, "--", path).toString("utf8").split("\t"); return meta.split(" "); };
const files = [];
for (let i = 0; i + 1 < parts.length && parts[i]; i += 2) {
  const path = parts[i + 1];
  if (parts[i] === "D") { files.push({ path, mode: entry(base, path)[0], content: null }); continue; }
  const [mode, type, sha] = entry("HEAD", path);
  if (type !== "blob") { console.error(path + " is a " + type + ", which can’t go in a pull request."); process.exit(1); }
  files.push({ path, mode, content: git("cat-file", "blob", sha).toString("base64") });
}
if (files.length > Number(most)) { console.error("The agent changed more than " + most + " files."); process.exit(1); }
const payload = zlib.gzipSync(JSON.stringify({ base, files })).toString("base64");
if (payload.length > Number(max)) { console.error("The agent’s change is too big to send back."); process.exit(1); }
const lines = payload.match(/.{1,4000}/g) || [];
console.log("::serveros-changes:: " + crypto.createHash("sha256").update(payload).digest("hex") + " " + lines.length);
for (const line of lines) console.log("::serveros-change:: " + line);
' "$base" "__SERVEROS_AGENT_MAX_CHANGE__" "__SERVEROS_AGENT_MAX_FILES__" >"$work/changes.txt" 2>"$work/changes-err.txt" \
        || fail "Couldn't send the agent's change back: $(head -c 300 "$work/changes-err.txt")"
      echo "::serveros-stat:: $stat"
    else
      [ -n "${GH_TOKEN:-}" ] || fail "The agent changed the code, but there's no GitHub token to push it with. Let ServerOS's GitHub App reach the repository, or set GH_TOKEN on the agent's service."
      GIT_TOKEN="$GH_TOKEN" GIT_CONFIG_NOSYSTEM=1 git -c core.hooksPath=/dev/null -c core.fsmonitor=false -c credential.helper= -c "credential.helper=$helper" push -q --no-verify "https://github.com/$SERVEROS_AGENT_REPO.git" "HEAD:refs/heads/$SERVEROS_AGENT_PR_BRANCH" \
        || fail "Couldn't push $SERVEROS_AGENT_PR_BRANCH. The token may not be allowed to write to the repository."
      command -v node >/dev/null 2>&1 || fail "Pushed $SERVEROS_AGENT_PR_BRANCH, but Node isn't here to open the pull request."
      pr=$(GIT_TOKEN="${GH_TOKEN:-}" SERVEROS_AGENT_BODY="$work/out.txt" SERVEROS_AGENT_NAME="$agent_name" SERVEROS_AGENT_MODEL_USED="$model" SERVEROS_AGENT_CHECKED="${checked:-}" SERVEROS_AGENT_STAT="$stat" node -e '
const [repo, head, base, title] = process.argv.slice(1);
const e = process.env;
const esc = (v) => String(v ?? "").replace(/&/g, "&amp;").replace(/</g, "&lt;").replace(/>/g, "&gt;").replace(/"/g, "&quot;");
const url = /^https:\/\//.test(e.SERVEROS_AGENT_RUN_URL || "") ? e.SERVEROS_AGENT_RUN_URL : "";
const run = url ? `<a href="${esc(url)}">${esc(e.SERVEROS_AGENT_RUN || "the run")}</a>` : esc(e.SERVEROS_AGENT_RUN || "A workflow run");
const summary = require("fs").readFileSync(e.SERVEROS_AGENT_BODY, "utf8").trim().slice(0, 50000);
const rows = [
  ["Agent", `${esc(e.SERVEROS_AGENT_NAME)} &nbsp;<code>${esc(e.SERVEROS_AGENT_MODEL_USED)}</code>`],
  ["Workflow", run],
  e.SERVEROS_AGENT_TRIGGER ? ["Started by", esc(e.SERVEROS_AGENT_TRIGGER)] : null,
  e.SERVEROS_AGENT_CHECKED ? ["Check", `Passed &nbsp;<code>${esc(e.SERVEROS_AGENT_CHECKED)}</code>`] : null,
  e.SERVEROS_AGENT_STAT ? ["Changes", esc(e.SERVEROS_AGENT_STAT)] : null,
].filter(Boolean);
const ask = (e.SERVEROS_AGENT_ASK || "").trim();
const body = [
  `<b>ServerOS</b> &nbsp;<code>coding agent</code> &nbsp;·&nbsp; ${esc(e.SERVEROS_AGENT_NAME)} &nbsp;<code>${esc(e.SERVEROS_AGENT_MODEL_USED)}</code>`,
  "",
  summary || "_The agent didn’t write a summary._",
  "",
  "<details><summary>Run details</summary>\n\n<table>" + rows.map(([k, v]) => `<tr><td><b>${k}</b></td><td>${v}</td></tr>`).join("") + "</table>\n\n"
    + (ask ? "<b>What it was asked</b>\n\n~~~text\n" + ask.replace(/~~~/g, "~ ~ ~") + "\n~~~\n" : "") + "\n</details>",
  "",
  `<sub>Made on your own machine by <a href="https://serveros.com">ServerOS</a>. Nothing is merged without you.` + (url ? ` · <a href="${esc(url)}">Open the run</a>` : "") + "</sub>",
].join("\n");
fetch((process.env.SERVEROS_GITHUB_API || "https://api.github.com") + "/repos/" + repo + "/pulls", {
  method: "POST",
  headers: { authorization: "Bearer " + process.env.GIT_TOKEN, accept: "application/vnd.github+json", "user-agent": "ServerOS-Agent" },
  body: JSON.stringify({ title, head, base, body }),
}).then(async (r) => {
  const j = await r.json().catch(() => ({}));
  if (!r.ok) { console.error(j.message || ("GitHub answered " + r.status)); process.exit(1); }
  console.log(j.html_url);
}).catch((e) => { console.error(String(e)); process.exit(1); });
' "$SERVEROS_AGENT_REPO" "$SERVEROS_AGENT_PR_BRANCH" "$SERVEROS_AGENT_BRANCH" "$SERVEROS_AGENT_TITLE" 2>"$work/pr.txt") \
        || fail "Pushed $SERVEROS_AGENT_PR_BRANCH, but couldn't open the pull request: $(head -c 300 "$work/pr.txt")"
      echo "::serveros-pr:: $pr"
    fi
  else
    note "The agent didn't change any code, so there's no pull request."
  fi
fi

echo "::serveros-summary::"
head -c 6000 "$work/out.txt" | grep -v '^::serveros-' | head -n 200
echo
[ -s "$work/changes.txt" ] && cat "$work/changes.txt"
exit 0
SH;
}

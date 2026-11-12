<?php

namespace App\Support\Workflows;

use App\Models\Workspace;
use App\Support\GitHub\GitHubApp;
use RuntimeException;

final class ReleaseSpec
{
    public const DEFAULT_USER = 'www-data';

    public const MAX_STEPS = 12;

    public const MODES = ['in_place', 'atomic'];

    public const LARAVEL_SHARED = ['.env', 'storage'];

    public const LARAVEL_TRIM = ['node_modules'];

    public const MAX_KEEP = 10;

    public const LARAVEL_STEPS = [
        ['run' => 'composer install --no-dev --no-interaction --prefer-dist --optimize-autoloader', 'again' => true],
        ['run' => 'php artisan storage:link', 'again' => true],
        ['run' => 'npm ci && npm run build', 'again' => true],
        ['run' => 'php artisan migrate --force', 'again' => false],
        ['run' => 'php artisan optimize', 'again' => true],
        ['run' => 'php artisan queue:restart', 'again' => true],
    ];

    /** @return list<array{run: string, again: bool}>|null */
    public static function steps(mixed $json): ?array
    {
        $decoded = is_string($json) && $json !== '' ? json_decode($json, true) : [];
        if (! is_array($decoded) || count($decoded) > self::MAX_STEPS) {
            return null;
        }
        $steps = [];
        foreach ($decoded as $step) {
            $run = is_array($step) ? trim((string) ($step['run'] ?? '')) : '';
            if ($run === '') {
                continue;
            }
            $steps[] = ['run' => mb_substr($run, 0, 500), 'again' => (bool) ($step['again'] ?? true)];
        }

        return $steps;
    }

    /** @return array{0: string, 1: string}|null */
    public static function github(string $repo): ?array
    {
        $repo = trim($repo);
        if (preg_match('#^(?:https://github\.com/|git@github\.com:)?([A-Za-z0-9_.-]+)/([A-Za-z0-9_.-]+?)(?:\.git)?/?$#', $repo, $m) !== 1) {
            return null;
        }

        return [$m[1], $m[2]];
    }

    /** @return list<string> */
    public static function list(mixed $value): array
    {
        return array_values(array_filter(array_map('trim', preg_split('/[\n,]+/', (string) $value) ?: []), fn (string $v) => $v !== ''));
    }

    public static function validInside(string $path): bool
    {
        return preg_match('#^[A-Za-z0-9._-]+(/[A-Za-z0-9._-]+)*$#', $path) === 1
            && ! in_array('..', explode('/', $path), true)
            && ! in_array('.', explode('/', $path), true)
            && explode('/', $path)[0] !== '.git';
    }

    /** @param  array<string, mixed>  $rules */
    public static function atomic(array $rules): bool
    {
        return ($rules['mode'] ?? 'in_place') === 'atomic';
    }

    public static function validUser(string $user): bool
    {
        return preg_match('/^[a-z_][a-z0-9_-]{0,31}$/', $user) === 1 && $user !== 'root';
    }

    public static function validPath(string $path): bool
    {
        return str_starts_with($path, '/') && $path !== '/' && ! str_contains($path, '..') && strlen($path) <= 255;
    }

    public static function validBranch(string $branch): bool
    {
        return preg_match('#^[A-Za-z0-9._/-]{1,200}$#', $branch) === 1 && ! str_contains($branch, '..');
    }

    /**
     * @param  array<string, mixed>  $rules
     * @param  array<string, mixed>  $trigger
     */
    public static function commit(Workspace $workspace, array $rules, array $trigger): string
    {
        [$owner, $name] = self::github((string) ($rules['repo'] ?? '')) ?? throw new RuntimeException('The repository should be owner/name on GitHub.');
        $branch = (string) (($rules['branch'] ?? '') ?: 'main');

        $pushed = (string) ($trigger['commit'] ?? $trigger['after'] ?? $trigger['sha'] ?? '');
        $sameRepo = ! isset($trigger['repo']) || strcasecmp((string) $trigger['repo'], "{$owner}/{$name}") === 0;
        $sameBranch = ! isset($trigger['branch']) || $trigger['branch'] === $branch;
        if (preg_match('/^[0-9a-f]{40}$/', $pushed) === 1 && $sameRepo && $sameBranch) {
            return $pushed;
        }

        return app(GitHubApp::class)->headCommit($workspace, $owner, $name, $branch)
            ?? throw new RuntimeException("Couldn’t find the latest commit on {$branch} of {$owner}/{$name}. Check the branch exists and ServerOS’s GitHub App can read the repository.");
    }

    /**
     * @param  array<string, mixed>  $rules
     * @return array<string, mixed>
     */
    public static function job(Workspace $workspace, array $rules, string $commit): array
    {
        [$owner, $name] = self::github((string) ($rules['repo'] ?? '')) ?? throw new RuntimeException('The repository should be owner/name on GitHub.');
        $token = app(GitHubApp::class)->tokenFor($workspace, $owner, $name);

        $steps = [];
        foreach (self::steps($rules['steps'] ?? null) ?? [] as $i => $step) {
            $steps[] = [
                'name' => self::stepName($step['run'], $i),
                'run' => $step['run'],
                'on_rollback' => $step['again'],
                'timeout_secs' => 900,
            ];
        }

        $reload = [
            ...array_map(fn (string $key) => ['service' => $key, 'action' => 'reload'], self::list($rules['reload'] ?? '')),
            ...array_map(fn (string $key) => ['service' => $key, 'action' => 'restart'], self::list($rules['restart'] ?? '')),
        ];
        $atomic = self::atomic($rules);

        return array_filter([
            'type' => 'release',
            'service' => (string) $rules['service'],
            'path' => rtrim((string) $rules['path'], '/'),
            'user' => (string) (($rules['user'] ?? '') ?: self::DEFAULT_USER),
            'repo' => "https://github.com/{$owner}/{$name}.git",
            'commit' => $commit,
            'steps' => $steps,
            'reload' => $reload,
            'health_url' => ($rules['health_url'] ?? '') ?: null,
            'discard_changes' => (bool) ($rules['discard_changes'] ?? false),
            'repo_token' => $token,
            'mode' => $atomic ? 'atomic' : 'in_place',
            'shared' => $atomic ? self::list($rules['shared'] ?? implode("\n", self::LARAVEL_SHARED)) : null,
            'trim' => $atomic ? self::list($rules['trim'] ?? '') : null,
            'keep' => $atomic ? max(1, min(self::MAX_KEEP, (int) ($rules['keep'] ?? 3))) : null,
        ], fn ($v) => $v !== null);
    }

    private static function stepName(string $run, int $i): string
    {
        $words = preg_split('/\s+/', preg_replace('/^(php artisan|npm run|npm|composer|yarn|pnpm)\s+/', '', $run) ?? $run) ?: [];
        $name = strtolower(preg_replace('/[^A-Za-z0-9:_-]/', '', $words[0] ?? '') ?? '');

        return $name === '' ? 'step '.($i + 1) : mb_substr($name, 0, 40);
    }
}

<?php

namespace App\Support\Deploys;

use App\Models\Machine;
use App\Models\User;
use App\Support\DaemonJobQueue;
use App\Support\ServiceTemplates\RepositoryDetector;
use App\Support\ServiceTemplates\ServiceTemplate;
use App\Support\ServiceTemplates\TemplateDeployment;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

final class DeployRequest
{
    public const DOMAIN_PATTERN = '/^(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z]{2,}$/i';

    public const SERVICE_PATTERN = '/^[a-z][a-z0-9-]*$/';

    public const COMMIT_PATTERN = '/^[a-fA-F0-9]{40}$/';

    public static function bareDomain(mixed $domain): mixed
    {
        return is_string($domain)
            ? preg_replace(['#^[a-z]+://#i', '#[/?\#].*$#', '#:\d+$#'], '', strtolower(trim($domain)))
            : $domain;
    }

    /** @return array<string, list<string>> */
    public static function gitRules(): array
    {
        return [
            'service' => ['required', 'string', 'max:63', 'regex:'.self::SERVICE_PATTERN],
            'repo' => ['required', 'url', 'max:500'],
            'commit' => ['required', 'string', 'regex:'.self::COMMIT_PATTERN],
            'port' => ['required', 'integer', 'between:1,65535'],
            'health_path' => ['required', 'string', 'max:200', 'regex:/^\/[\x21-\x7e]*$/'],
            'domain' => ['nullable', 'string', 'max:253', 'regex:'.self::DOMAIN_PATTERN],
            'wildcard' => ['sometimes', 'boolean'],
        ];
    }

    /** @return array<string, list<string>> */
    public static function templateRules(ServiceTemplate $template): array
    {
        return [
            'service' => ['required', 'string', 'max:63', 'regex:'.self::SERVICE_PATTERN],
            'port' => ['nullable', 'integer', 'between:1,65535'],
            'repo' => [$template->isApp() ? 'required' : 'nullable', 'url', 'max:500'],
            'commit' => [$template->isApp() ? 'required' : 'nullable', 'string', 'regex:'.self::COMMIT_PATTERN],
            'health_path' => ['nullable', 'string', 'max:200', 'regex:/^\/[\x21-\x7e]*$/'],
            'domain' => ['nullable', 'string', 'max:253', 'regex:'.self::DOMAIN_PATTERN],
            'wildcard' => ['sometimes', 'boolean'],
            'env' => ['nullable', 'array'],
            'env.*' => ['nullable', 'string', 'max:2000'],
            'extra_env' => ['nullable', 'string', 'max:10000'],
            'php' => ['nullable', 'string', Rule::in(RepositoryDetector::PHP_VERSIONS)],
        ];
    }

    /** @return list<string> */
    public static function domains(?string $domain, bool $wildcard = false): array
    {
        if (blank($domain)) {
            return [];
        }
        $domain = strtolower($domain);

        return $wildcard ? [$domain, '*.'.$domain] : [$domain];
    }

    /** @param  array<int, mixed>  $domains */
    public static function isWildcard(array $domains): bool
    {
        $first = $domains[0] ?? null;

        return is_string($first) && in_array('*.'.$first, $domains, true);
    }

    /**
     * @param  array<int, mixed>  $domains
     * @return list<string>
     */
    public static function plainDomains(array $domains): array
    {
        return array_values(array_filter($domains, fn ($domain) => is_string($domain) && ! str_starts_with($domain, '*.')));
    }

    public static function assertWildcard(Machine $machine, ?string $domain, bool $wildcard, string $field = 'wildcard'): void
    {
        if (! $wildcard) {
            return;
        }
        if (blank($domain)) {
            throw ValidationException::withMessages([$field => 'Add a domain first: wildcard subdomains sit under it.']);
        }
        if (! DaemonJobQueue::canWildcard($machine)) {
            throw ValidationException::withMessages([$field => 'This machine’s daemon is too old for wildcard subdomains. It updates itself, or run “sudo serverosd update” on the server.']);
        }
    }

    public static function assertPublicRepo(string $repo): void
    {
        $parts = parse_url($repo);
        if (! is_array($parts) || ($parts['scheme'] ?? null) !== 'https' || empty($parts['host']) || isset($parts['user']) || isset($parts['pass'])) {
            throw ValidationException::withMessages(['repo' => 'Use an HTTPS Git repository address, without a password or token in it.']);
        }
    }

    public static function assertDocker(Machine $machine, ?User $user = null): void
    {
        if ($machine->hasDocker() !== false) {
            return;
        }

        $rescanning = $user !== null && app(DaemonJobQueue::class)->rescan($machine, $user) !== null;

        throw ValidationException::withMessages(['form' => $rescanning
            ? 'Docker isn’t showing on '.$machine->name.' yet, so ServerOS is checking the machine again now. If you’ve just installed it, deploy again in a few seconds. If not, run `curl -fsSL https://get.docker.com | sh` on it as root first.'
            : 'Docker isn’t installed on '.$machine->name.'. Run `curl -fsSL https://get.docker.com | sh` on it as root, then deploy again.']);
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public static function gitJob(array $data): array
    {
        return [
            'type' => 'deploy',
            'service' => $data['service'],
            'repo' => $data['repo'],
            'commit' => strtolower($data['commit']),
            'dockerfile' => 'Dockerfile',
            'env' => (object) [],
            'health' => ['path' => $data['health_path'], 'expected_status' => 200, 'timeout_secs' => 30, 'retries' => 5],
            'domains' => self::domains($data['domain'] ?? null, (bool) ($data['wildcard'] ?? false)),
            'port' => (int) $data['port'],
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array{template: string, generated: list<array{key: string, label: string, value: string}>, kept: list<string>, url: string|null, localUrl: string|null, tunnel: string|null, port: int, notes: string|null}
     */
    public static function templateResult(TemplateDeployment $deployment, array $data): array
    {
        $template = $deployment->template;
        $machine = $deployment->machine;

        return [
            'template' => $template->name,
            'generated' => $deployment->generated,
            'kept' => $deployment->kept,
            'url' => $template->web && (filled($data['domain'] ?? null) || $machine->localAddress($deployment->port) !== null) ? $deployment->url : null,
            'localUrl' => $template->web && ! $template->isApp() ? "http://127.0.0.1:{$deployment->port}" : null,
            'tunnel' => $template->web && ! $template->isApp() && filled($machine->ip_address) && $machine->localAddress($deployment->port) === null
                ? "ssh -N -L {$deployment->port}:127.0.0.1:{$deployment->port} root@{$machine->ip_address}"
                : null,
            'port' => $deployment->port,
            'notes' => $template->notesFor($machine),
        ];
    }
}

<?php

namespace App\Support\Machines;

use App\Models\Machine;
use App\Support\DaemonJobQueue;
use App\Support\Deploys\DeployRequest;

final class ServiceClones
{
    private const FIRST_PORT = 18000;

    /** @param  array<string, mixed>  $service */
    public static function unavailable(Machine $machine, array $service): ?string
    {
        return match (true) {
            ! str_starts_with((string) ($service['key'] ?? ''), 'docker:') => 'Only containers can be cloned for now.',
            ! DaemonJobQueue::canClone($machine) => 'This machine’s daemon is too old to clone services. It updates itself, or run “sudo serverosd update” on the server.',
            ! ($service['managed'] ?? false) => 'Adopt this service first so ServerOS can control it.',
            default => app(DaemonJobQueue::class)->unavailable($machine),
        };
    }

    /** @param  array<string, mixed>  $service */
    public static function controlsHost(array $service): bool
    {
        foreach (array_filter(explode(', ', (string) ($service['details']['mounts'] ?? ''))) as $mount) {
            $source = explode(':', $mount, 2)[0];
            $source = $source === '/' ? '/' : rtrim($source, '/');
            if (preg_match('#(docker|containerd|podman)[^/]*\.sock$#', $source) === 1
                || in_array($source, ['/', '/var', '/var/run', '/run', '/var/run/docker', '/run/docker', '/run/containerd'], true)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    public static function normalise(array $input): array
    {
        if (is_string($input['domain'] ?? null)) {
            $input['domain'] = DeployRequest::bareDomain($input['domain']) ?: null;
        }

        return $input;
    }

    /**
     * @return array<string, mixed>
     */
    public static function rules(Machine $machine): array
    {
        return [
            'name' => ['required', 'string', 'max:63', 'regex:/^[a-z][a-z0-9-]*$/', function (string $attribute, mixed $value, \Closure $fail) use ($machine) {
                if (self::nameTaken($machine, (string) $value)) {
                    $fail('Something on this machine is already called that.');
                }
            }],
            'port' => ['nullable', 'integer', 'min:1024', 'max:65535', function (string $attribute, mixed $value, \Closure $fail) use ($machine) {
                if (in_array((int) $value, self::usedPorts($machine), true)) {
                    $fail('Something on this machine already listens on that port.');
                }
            }],
            'container_port' => ['nullable', 'integer', 'min:1', 'max:65535'],
            'domain' => ['nullable', 'string', 'max:253', 'regex:'.DeployRequest::DOMAIN_PATTERN],
            'copy_data' => ['required', 'boolean'],
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public static function job(Machine $machine, string $service, array $data): array
    {
        $domain = filled($data['domain'] ?? null) ? (string) $data['domain'] : null;

        return [
            'type' => 'service_clone',
            'service' => $service,
            'name' => $data['name'],
            'port' => isset($data['port']) ? (int) $data['port'] : ($domain ? self::freePort($machine) : null),
            'container_port' => isset($data['container_port']) ? (int) $data['container_port'] : null,
            'domain' => $domain,
            'copy_data' => (bool) $data['copy_data'],
        ];
    }

    public static function freePort(Machine $machine): int
    {
        $used = array_flip(self::usedPorts($machine));
        $port = self::FIRST_PORT;
        while (isset($used[$port]) && $port < 65535) {
            $port++;
        }

        return $port;
    }

    public static function suggestedName(Machine $machine, string $name): string
    {
        $base = substr(trim((string) preg_replace('/[^a-z0-9-]+/', '-', strtolower($name)), '-'), 0, 50) ?: 'service';
        $base = ctype_alpha($base[0]) ? $base : "s-{$base}";
        $candidate = "{$base}-test";
        for ($n = 2; self::nameTaken($machine, $candidate) && $n < 100; $n++) {
            $candidate = "{$base}-test-{$n}";
        }

        return $candidate;
    }

    /** @return list<int> */
    private static function usedPorts(Machine $machine): array
    {
        $ports = [];
        foreach ($machine->inventory['listeners'] ?? [] as $listener) {
            if (is_array($listener) && isset($listener['port'])) {
                $ports[] = (int) $listener['port'];
            }
        }

        return array_values(array_unique($ports));
    }

    private static function nameTaken(Machine $machine, string $name): bool
    {
        foreach ($machine->inventory['services'] ?? [] as $service) {
            if (is_array($service) && ltrim((string) ($service['name'] ?? ''), '/') === $name) {
                return true;
            }
        }

        return false;
    }
}

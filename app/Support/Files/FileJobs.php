<?php

namespace App\Support\Files;

use App\Models\DaemonJob;
use App\Models\Machine;
use App\Support\Deploys\AppReleases;
use App\Support\Deploys\ServiceLabels;
use Illuminate\Support\Facades\Crypt;

final class FileJobs
{
    public const array OPS = [
        'list' => ['file_list', 'list_dir'],
        'read' => ['file_read', 'read_file'],
        'write' => ['file_write', 'write_file'],
        'delete' => ['file_delete', 'delete_file'],
    ];

    public const array KINDS = ['file_list', 'file_read', 'file_write', 'file_delete'];

    public const string APPS_DIR = '/var/lib/serveros/apps';

    public const int MAX_WRITE_BYTES = 512 * 1024;

    public const int MAX_READ_BYTES = 2 * 1024 * 1024;

    /**
     * @param  array<string, mixed>  $result
     * @return array<string, mixed>
     */
    public static function seal(DaemonJob $job, array $result): array
    {
        if ($job->kind !== 'file_read' || ! is_string($result['content_b64'] ?? null)) {
            return $result;
        }

        return ['size' => (int) ($result['size'] ?? 0), 'sealed' => Crypt::encryptString($result['content_b64'])];
    }

    public static function forgetContent(DaemonJob $job): void
    {
        $command = $job->command;
        if ($job->kind !== 'file_write' || ! isset($command['job']['content_b64'])) {
            return;
        }

        unset($command['job']['content_b64']);
        $job->forceFill(['command' => $command])->save();
    }

    /** @return array<string, mixed>|null */
    public static function deliver(DaemonJob $job): ?array
    {
        $result = $job->result;
        if ($job->kind !== 'file_read' || ! is_string($result['sealed'] ?? null)) {
            return $result;
        }

        $job->forceFill(['result' => ['size' => $result['size'] ?? null, 'delivered' => true]])->save();

        return ['size' => $result['size'] ?? null, 'content_b64' => Crypt::decryptString($result['sealed'])];
    }

    /** @return list<array{label: string, path: string}> */
    public static function places(Machine $machine): array
    {
        $places = [];
        foreach ($machine->inventory['services'] ?? [] as $service) {
            if (! is_array($service)) {
                continue;
            }
            foreach (self::placesFor($machine, $service) as $place) {
                $places[$place['path']] ??= $place;
            }
        }
        usort($places, fn (array $a, array $b) => strcasecmp($a['label'], $b['label']));

        return $places;
    }

    public const string JOBS_DIR = '/var/lib/serveros/jobs';

    private const array SYSTEM_ROOTS = [
        '/etc', '/proc', '/sys', '/dev', '/run', '/var/run', '/boot', '/root', '/bin', '/sbin',
        '/lib', '/lib64', '/usr', '/var/lib/docker/containers',
    ];

    /**
     * @param  array<string, mixed>  $service
     * @return list<array{label: string, path: string}>
     */
    public static function placesFor(Machine $machine, array $service): array
    {
        $release = AppReleases::parse($service['name'] ?? null);
        if ($release !== null && isset(AppReleases::live($machine)[$release['app']])) {
            return [['label' => $release['app'], 'path' => self::APPS_DIR.'/'.self::folderName($release['app'])]];
        }
        if (($service['details']['managed'] ?? null) !== 'true') {
            return [];
        }

        $name = ServiceLabels::display($machine, $service);
        $created = ($service['origin'] ?? null) === 'created';
        $places = [];
        $add = function (string $label, mixed $path) use (&$places): void {
            if (is_string($path) && self::openable($path) && ! in_array(rtrim($path, '/'), array_column($places, 'path'), true)) {
                $places[] = ['label' => $label, 'path' => rtrim($path, '/')];
            }
        };

        if ($created) {
            if (is_string($service['working_dir'] ?? null) && str_starts_with($service['working_dir'], self::JOBS_DIR.'/')) {
                $add("{$name} · compose files", $service['working_dir']);
            }
        } else {
            $add($name, $service['working_dir'] ?? null);
            $add("{$name} · data", $service['data_dir'] ?? null);
        }

        if (str_starts_with((string) ($service['key'] ?? ''), 'docker:')) {
            foreach (explode(', ', (string) ($service['details']['mounts'] ?? '')) as $mount) {
                $at = strrpos($mount, ':');
                if ($at !== false) {
                    $add("{$name} · ".substr($mount, $at + 1), substr($mount, 0, $at));
                }
            }
        }

        return $places;
    }

    private static function openable(string $path): bool
    {
        $path = rtrim($path, '/');
        if (! str_starts_with($path, '/') || str_contains($path, '/..')) {
            return false;
        }
        foreach (self::SYSTEM_ROOTS as $system) {
            if ($path === $system || str_starts_with($path, $system.'/') || str_starts_with($system, $path.'/')) {
                return false;
            }
        }

        return true;
    }

    private static function folderName(string $app): string
    {
        return (string) preg_replace('/[^A-Za-z0-9_-]/', '-', $app);
    }
}

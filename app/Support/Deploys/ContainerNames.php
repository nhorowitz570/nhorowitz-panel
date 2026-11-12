<?php

namespace App\Support\Deploys;

final class ContainerNames
{
    private const GENERAL_IMAGES = ['yolks', 'games', 'installers', 'images'];

    private const SPELLINGS = [
        'mysql' => 'MySQL', 'mariadb' => 'MariaDB', 'postgres' => 'PostgreSQL', 'postgresql' => 'PostgreSQL',
        'mongo' => 'MongoDB', 'mongodb' => 'MongoDB', 'redis' => 'Redis', 'nodejs' => 'Node.js', 'node' => 'Node.js',
        'python' => 'Python', 'java' => 'Java', 'golang' => 'Go', 'dotnet' => '.NET', 'bun' => 'Bun', 'deno' => 'Deno',
        'php' => 'PHP', 'nginx' => 'nginx', 'caddy' => 'Caddy',
    ];

    /** @param  array<string, mixed>  $service */
    public static function found(array $service): ?string
    {
        $name = ltrim((string) ($service['name'] ?? ''), '/');
        if (! str_starts_with((string) ($service['key'] ?? ''), 'docker:') || ! self::unnamed($name, $service)) {
            return null;
        }

        $what = self::fromImage((string) ($service['details']['image'] ?? ''), (string) ($service['kind'] ?? ''));
        $id = preg_match('/^([0-9a-f]{8})-/i', $name, $match) ? strtolower($match[1]) : null;

        return match (true) {
            $what !== null && $id !== null => "{$what} · {$id}",
            $what !== null => $what,
            default => null,
        };
    }

    /** @param  array<string, mixed>  $service */
    private static function unnamed(string $name, array $service): bool
    {
        $mounts = (string) ($service['details']['mounts'] ?? '');

        return preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $name) === 1
            || str_contains($mounts, '/run/wings/')
            || str_contains($mounts, '/var/lib/pterodactyl/');
    }

    public static function fromImage(string $image, string $kind = ''): ?string
    {
        $image = preg_replace('/@sha256:[0-9a-f]+$/i', '', trim($image)) ?? '';
        if ($image === '' || preg_match('/^sha256:/i', $image)) {
            return null;
        }

        $last = substr($image, (int) strrpos('/'.$image, '/'));
        [$repo, $tag] = array_pad(explode(':', $last, 2), 2, null);

        if ($kind === 'game_server' && preg_match('/^java/i', (string) $tag)) {
            return 'Minecraft';
        }
        $words = in_array(strtolower($repo), self::GENERAL_IMAGES, true) && $tag !== null && $tag !== 'latest'
            ? $tag
            : $repo;

        $words = explode(' ', strtolower(trim(preg_replace('/[-_.]+/', ' ', $words) ?? '')));
        if ($words === ['']) {
            return null;
        }
        $first = array_shift($words);

        return trim((self::SPELLINGS[$first] ?? ucfirst($first)).' '.implode(' ', $words));
    }
}

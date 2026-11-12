<?php

namespace App\Support\Deploys;

use App\Models\Machine;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

final class ServiceLabels
{
    /** @param  array<string, mixed>  $service */
    public static function identity(array $service): string
    {
        $app = AppReleases::parse($service['name'] ?? null)['app'] ?? null;
        if ($app !== null) {
            return "app:{$app}";
        }
        $key = (string) ($service['key'] ?? '');

        return str_starts_with($key, 'docker:') ? 'container:'.ltrim((string) ($service['name'] ?? $key), '/') : $key;
    }

    /** @param  array<string, mixed>  $service */
    public static function for(Machine $machine, array $service): ?string
    {
        return $machine->service_labels[self::identity($service)] ?? null;
    }

    /** @param  array<string, mixed>  $service */
    public static function set(Machine $machine, array $service, ?string $label): void
    {
        $labels = $machine->service_labels ?? [];
        $label = $label === null ? '' : trim($label);

        if ($label === '') {
            unset($labels[self::identity($service)]);
        } else {
            $labels[self::identity($service)] = $label;
        }

        $machine->forceFill(['service_labels' => $labels ?: null])->save();
    }

    /** @param  array<string, mixed>  $service */
    public static function isHidden(Machine $machine, array $service): bool
    {
        return in_array(self::identity($service), $machine->hidden_services ?? [], true);
    }

    /** @param  list<array<string, mixed>>  $services */
    public static function setHidden(Machine $machine, array $services, bool $hidden): void
    {
        $identities = array_map(self::identity(...), $services);
        $current = $machine->hidden_services ?? [];
        $next = $hidden
            ? array_values(array_unique([...$current, ...$identities]))
            : array_values(array_diff($current, $identities));
        sort($next);

        $machine->forceFill(['hidden_services' => $next ?: null]);
        $machine->forceFill(['services_count' => self::shownCount($machine, $machine->inventory['services'] ?? [])])->save();
    }

    /** @param  array<string, mixed>  $service */
    public static function logsMuted(Machine $machine, array $service): bool
    {
        return in_array(self::identity($service), $machine->log_muted_services ?? [], true);
    }

    /** @param  array<string, mixed>  $service */
    public static function setLogsMuted(Machine $machine, array $service, bool $muted): void
    {
        $identity = self::identity($service);
        $current = $machine->log_muted_services ?? [];
        $next = $muted ? array_values(array_unique([...$current, $identity])) : array_values(array_diff($current, [$identity]));
        sort($next);

        $machine->forceFill(['log_muted_services' => $next ?: null])->save();
    }

    /** @param  array<int, mixed>  $services */
    public static function shownCount(Machine $machine, array $services): int
    {
        $shown = array_filter($services, fn ($service) => is_array($service) && ! self::isHidden($machine, $service));

        return min(count($shown), 65535);
    }

    /** @param  array<string, mixed>  $service */
    public static function display(Machine $machine, array $service): string
    {
        return self::for($machine, $service)
            ?? AppReleases::parse($service['name'] ?? null)['app']
            ?? ContainerNames::found($service)
            ?? (string) ($service['name'] ?? $service['key'] ?? '');
    }

    /** @param  array<string, mixed>  $service */
    public static function iconUrl(Machine $machine, array $service): ?string
    {
        $path = $machine->service_icons[self::identity($service)] ?? null;

        return $path === null ? null : Storage::disk('public')->url($path);
    }

    /** @param  array<string, mixed>  $service */
    public static function setIcon(Machine $machine, array $service, ?UploadedFile $file): void
    {
        $icons = $machine->service_icons ?? [];
        $identity = self::identity($service);

        if (isset($icons[$identity])) {
            Storage::disk('public')->delete($icons[$identity]);
            unset($icons[$identity]);
        }
        if ($file !== null) {
            $icons[$identity] = $file->store("service-icons/{$machine->id}", 'public');
        }

        $machine->forceFill(['service_icons' => $icons ?: null])->save();
    }
}

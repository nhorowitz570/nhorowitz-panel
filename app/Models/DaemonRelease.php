<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 * @property string $version
 * @property string $arch
 * @property string $channel
 * @property string $sha256
 * @property string $signature
 * @property string|null $min_from
 * @property string|null $notes_url
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
#[Fillable(['version', 'arch', 'channel', 'sha256', 'signature', 'min_from', 'notes_url'])]
class DaemonRelease extends Model
{
    public const ARCHES = ['amd64', 'arm64'];

    public const CHANNELS = ['stable', 'canary'];

    public static function arch(string $reported): ?string
    {
        return match (strtolower($reported)) {
            'x86_64', 'amd64' => 'amd64',
            'aarch64', 'arm64' => 'arm64',
            default => null,
        };
    }

    public static function latest(string $channel, string $arch): ?self
    {
        $channels = $channel === 'canary' ? self::CHANNELS : ['stable'];

        return self::query()
            ->where('arch', $arch)
            ->whereIn('channel', $channels)
            ->get()
            ->sort(fn (self $a, self $b) => self::compare($b->version, $a->version))
            ->first();
    }

    public static function compare(string $a, string $b): int
    {
        $split = function (string $version): array {
            $version = explode('+', ltrim(trim($version), 'v'))[0];
            [$core, $pre] = array_pad(explode('-', $version, 2), 2, null);

            return [array_map('intval', array_pad(explode('.', $core), 3, '0')), $pre];
        };
        [$coreA, $preA] = $split($a);
        [$coreB, $preB] = $split($b);

        if (($order = $coreA <=> $coreB) !== 0) {
            return $order;
        }
        if ($preA === null || $preB === null) {
            return ($preA === null ? 1 : 0) - ($preB === null ? 1 : 0);
        }

        $partsA = explode('.', $preA);
        $partsB = explode('.', $preB);
        foreach ($partsA as $i => $part) {
            if (! array_key_exists($i, $partsB)) {
                return 1;
            }
            $other = $partsB[$i];
            $order = ctype_digit($part) && ctype_digit($other)
                ? (int) $part <=> (int) $other
                : (ctype_digit($part) ? -1 : (ctype_digit($other) ? 1 : strcmp($part, $other) <=> 0));
            if ($order !== 0) {
                return $order;
            }
        }

        return count($partsA) < count($partsB) ? -1 : 0;
    }

    public static function fileName(string $version, string $arch): string
    {
        return "serverosd-{$version}-linux-{$arch}";
    }

    public static function path(string $version, ?string $file = null): string
    {
        return rtrim((string) config('daemon.releases_path'), '/')."/{$version}".($file === null ? '' : "/{$file}");
    }

    public function binaryIsServed(): bool
    {
        $file = self::path($this->version, self::fileName($this->version, $this->arch));

        return is_file($file) && hash_equals(strtolower($this->sha256), (string) hash_file('sha256', $file));
    }

    public static function publishStableVersion(): ?string
    {
        $version = self::latest('stable', 'amd64')?->version;
        if ($version !== null) {
            $dir = rtrim((string) config('daemon.releases_path'), '/').'/stable';
            if (! is_dir($dir)) {
                mkdir($dir, 0755, true);
            }
            file_put_contents("{$dir}/VERSION", $version."\n");
        }

        return $version;
    }

    /** @return array{version: string, url: string, sha256: string, signature: string, channel: string, min_from: string|null, notes_url: string|null} */
    public function toCandidate(): array
    {
        return [
            'version' => $this->version,
            'url' => rtrim((string) config('daemon.releases_url'), '/')."/{$this->version}/".self::fileName($this->version, $this->arch),
            'sha256' => $this->sha256,
            'signature' => $this->signature,
            'channel' => $this->channel,
            'min_from' => $this->min_from,
            'notes_url' => $this->notes_url,
        ];
    }
}

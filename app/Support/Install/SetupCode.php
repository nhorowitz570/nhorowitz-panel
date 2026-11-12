<?php

namespace App\Support\Install;

use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Hash;
use Throwable;

final class SetupCode
{
    private const ALPHABET = 'ABCDEFGHJKMNPQRSTUVWXYZ23456789';

    private const LENGTH = 12;

    public function __construct(private ?string $path = null) {}

    public function path(): string
    {
        return $this->path ?? self::directory().'/setup-code.json';
    }

    public static function directory(): string
    {
        $directory = config('serveros.install.path');

        return is_string($directory) && $directory !== '' ? rtrim($directory, '/') : storage_path('app/private/install');
    }

    public function pending(): bool
    {
        return $this->stored() !== null;
    }

    public function current(): string
    {
        $stored = $this->stored();
        if ($stored !== null) {
            try {
                $code = Crypt::decryptString($stored['code']);
                if (Hash::check(self::normalize($code), $stored['hash'])) {
                    return self::format($code);
                }
            } catch (Throwable) {
            }
        }

        return $this->regenerate();
    }

    public function regenerate(): string
    {
        $code = '';
        for ($i = 0; $i < self::LENGTH; $i++) {
            $code .= self::ALPHABET[random_int(0, strlen(self::ALPHABET) - 1)];
        }

        $directory = dirname($this->path());
        if (! is_dir($directory)) {
            @mkdir($directory, 0700, true);
        }
        $contents = (string) json_encode([
            'hash' => Hash::make($code),
            'code' => Crypt::encryptString($code),
            'created_at' => now()->toIso8601String(),
        ]);
        $temporary = $this->path().'.'.bin2hex(random_bytes(4));
        file_put_contents($temporary, $contents);
        @chmod($temporary, 0600);
        rename($temporary, $this->path());

        return self::format($code);
    }

    public function matches(?string $given): bool
    {
        $stored = $this->stored();
        $given = self::normalize((string) $given);

        return $stored !== null && strlen($given) === self::LENGTH && Hash::check($given, $stored['hash']);
    }

    public function fingerprint(): ?string
    {
        $stored = $this->stored();

        return $stored === null ? null : hash('sha256', $stored['hash']);
    }

    public function forget(): void
    {
        if (is_file($this->path())) {
            @unlink($this->path());
        }
    }

    public static function format(string $code): string
    {
        return implode('-', str_split(self::normalize($code), 4));
    }

    public static function normalize(string $code): string
    {
        return (string) preg_replace('/[^A-Z0-9]/', '', strtoupper($code));
    }

    /** @return array{hash: string, code: string}|null */
    private function stored(): ?array
    {
        $path = $this->path();
        if (! is_file($path)) {
            return null;
        }

        $stored = json_decode((string) @file_get_contents($path), true);

        return is_array($stored) && is_string($stored['hash'] ?? null) && is_string($stored['code'] ?? null)
            ? ['hash' => $stored['hash'], 'code' => $stored['code']]
            : null;
    }
}

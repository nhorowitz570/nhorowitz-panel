<?php

namespace App\Support\Install;

use Dotenv\Dotenv;
use Illuminate\Support\Env;
use RuntimeException;
use Throwable;

final class EnvFile
{
    public function __construct(private ?string $path = null) {}

    public function path(): string
    {
        $path = $this->path ?? app()->environmentFilePath();

        if (is_link($path)) {
            $target = (string) readlink($path);
            $path = str_starts_with($target, '/') ? $target : dirname($path).'/'.$target;
        }

        return $path;
    }

    public function writable(): bool
    {
        $path = $this->path();

        return is_file($path) ? is_writable($path) : is_dir(dirname($path)) && is_writable(dirname($path));
    }

    /** @return array<string, string|null> */
    public function values(): array
    {
        $path = $this->path();
        if (! is_file($path)) {
            return [];
        }

        try {
            return Dotenv::parse((string) file_get_contents($path));
        } catch (Throwable) {
            return [];
        }
    }

    public function get(string $key): ?string
    {
        return $this->values()[$key] ?? null;
    }

    public function managedElsewhere(string $key): bool
    {
        $environment = Env::getRepository()->get($key);
        if ($environment === null) {
            return false;
        }

        $values = $this->values();

        return ! array_key_exists($key, $values) || (string) $values[$key] !== $environment;
    }

    /** @param  array<string, string|null>  $values */
    public function set(array $values): void
    {
        $path = $this->path();
        $lines = is_file($path) ? preg_split('/\r\n|\n|\r/', (string) file_get_contents($path)) : [];
        $lines = $lines === false ? [] : $lines;
        if ($lines !== [] && end($lines) === '') {
            array_pop($lines);
        }

        $added = [];
        foreach ($values as $key => $value) {
            if (preg_match('/^[A-Z][A-Z0-9_]*$/', $key) !== 1) {
                throw new RuntimeException("{$key} isn't a setting name.");
            }
            $line = $key.'='.self::quote($value ?? '');

            $found = null;
            foreach ($lines as $index => $existing) {
                if (preg_match('/^\s*(export\s+)?'.preg_quote($key, '/').'\s*=/', $existing) === 1) {
                    $found = $index;
                }
            }

            if ($found === null) {
                $added[] = $line;
            } else {
                $lines[$found] = $line;
            }
        }

        if ($added !== []) {
            if ($lines !== [] && trim((string) end($lines)) !== '') {
                $lines[] = '';
            }
            array_push($lines, ...$added);
        }

        $this->write(implode("\n", $lines)."\n");
    }

    public static function quote(string $value): string
    {
        if (preg_match('/[\r\n\0]/', $value) === 1) {
            throw new RuntimeException('A setting can\'t have a line break in it.');
        }
        if ($value === '' || preg_match('/^[A-Za-z0-9_.\/:@+,-]+$/', $value) === 1) {
            return $value;
        }
        if (! str_contains($value, "'")) {
            return "'".$value."'";
        }

        return '"'.addcslashes($value, '\\"$').'"';
    }

    private function write(string $contents): void
    {
        $path = $this->path();
        $directory = dirname($path);

        if (is_dir($directory) && is_writable($directory) && $this->ours($path)) {
            $temporary = @tempnam($directory, '.env.');
            if ($temporary !== false) {
                @chmod($temporary, 0600);
                if (@file_put_contents($temporary, $contents) === strlen($contents) && @rename($temporary, $path)) {
                    @chmod($path, 0600);

                    return;
                }
                @unlink($temporary);
            }
        }

        $new = ! is_file($path);
        if (@file_put_contents($path, $contents, LOCK_EX) !== strlen($contents)) {
            throw new RuntimeException("Couldn't write {$path}.");
        }
        if ($new) {
            @chmod($path, 0600);
        }
    }

    private function ours(string $path): bool
    {
        return ! is_file($path) || ! function_exists('posix_geteuid') || fileowner($path) === posix_geteuid();
    }
}

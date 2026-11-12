<?php

namespace App\Support;

final class Brand
{
    public static function company(): ?string
    {
        return self::filled('company');
    }

    public static function signOff(): string
    {
        return self::company() ?? (string) config('app.name');
    }

    public static function address(): ?string
    {
        return self::filled('address');
    }

    public static function website(): string
    {
        return self::filled('website') ?? (string) (parse_url((string) config('app.url'), PHP_URL_HOST) ?: config('app.url'));
    }

    public static function agentEmail(): string
    {
        $email = self::filled('agent_email') ?? 'agent@'.config('app.domain');

        return filter_var($email, FILTER_VALIDATE_EMAIL) !== false && preg_match('/^[A-Za-z0-9._%+@-]+$/', $email) === 1
            ? $email
            : 'agent@localhost';
    }

    /** @return array{company: string|null, terms: string|null, privacy: string|null, source: string|null} */
    public static function shared(): array
    {
        return [
            'company' => self::company(),
            'terms' => self::filled('terms_url'),
            'privacy' => self::filled('privacy_url'),
            'source' => self::filled('source_url'),
        ];
    }

    private static function filled(string $key): ?string
    {
        $value = config("serveros.brand.{$key}");

        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }
}

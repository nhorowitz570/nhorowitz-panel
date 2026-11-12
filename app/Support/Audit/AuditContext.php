<?php

namespace App\Support\Audit;

use App\Http\Middleware\TrustCloudflare;
use App\Models\User;
use App\Support\Admin\Impersonation;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

final class AuditContext
{
    public const string SUPPORT = 'ServerOS Support';

    private const string REQUEST_ID = 'audit.request_id';

    private const string RECORDED = 'audit.recorded';

    /** @var list<array<string, mixed>> */
    private static array $via = [];

    public static function viewAsSession(?User $user): ?int
    {
        $request = self::request();
        if ($request === null || $user === null || $request->user()?->getAuthIdentifier() !== $user->id) {
            return null;
        }
        if ((self::via()['via'] ?? null) === 'workflow') {
            return null;
        }

        return Impersonation::sessionId($request);
    }

    public static function isRequestUser(User $user): bool
    {
        return self::request()?->user()?->getAuthIdentifier() === $user->id;
    }

    public static function supportName(): string
    {
        return is_string($word = __(self::SUPPORT)) ? $word : self::SUPPORT;
    }

    /**
     * @template T
     *
     * @param  callable(): T  $work
     * @return T
     */
    public static function inEnglish(callable $work): mixed
    {
        $locale = app()->getLocale();
        if ($locale === 'en') {
            return $work();
        }
        app()->setLocale('en');
        try {
            return $work();
        } finally {
            app()->setLocale($locale);
        }
    }

    public static function goneKey(string $email): string
    {
        return 'gone-'.substr(hash_hmac('sha256', mb_strtolower($email), (string) config('app.key')), 0, 12);
    }

    /**
     * @template T
     *
     * @param  array<string, mixed>  $via
     * @param  callable(): T  $work
     * @return T
     */
    public static function within(array $via, callable $work): mixed
    {
        self::$via[] = $via;
        try {
            return $work();
        } finally {
            array_pop(self::$via);
        }
    }

    /** @return array<string, mixed> */
    public static function via(): array
    {
        return self::$via === [] ? [] : self::$via[array_key_last(self::$via)];
    }

    public static function sessionId(): ?int
    {
        $request = self::request();

        return $request === null ? null : Impersonation::sessionId($request);
    }

    public static function ip(): ?string
    {
        return self::request()?->ip();
    }

    public static function country(): ?string
    {
        $country = self::request()?->attributes->get(TrustCloudflare::COUNTRY);

        return is_string($country) ? $country : null;
    }

    public static function userAgent(): ?string
    {
        $agent = self::request()?->userAgent();

        return $agent === null || $agent === '' ? null : mb_substr($agent, 0, 255);
    }

    public static function requestId(): ?string
    {
        $request = self::request();
        if ($request === null) {
            return null;
        }
        if (! $request->attributes->has(self::REQUEST_ID)) {
            $request->attributes->set(self::REQUEST_ID, (string) Str::uuid());
        }

        return $request->attributes->get(self::REQUEST_ID);
    }

    public static function markRecorded(): void
    {
        self::request()?->attributes->set(self::RECORDED, true);
    }

    public static function nothingChanged(): void
    {
        self::markRecorded();
    }

    public static function forgetRecorded(): void
    {
        self::request()?->attributes->remove(self::RECORDED);
    }

    public static function recorded(?Request $request = null): bool
    {
        return (bool) ($request ?? self::request())?->attributes->get(self::RECORDED, false);
    }

    /** @param  array<string, mixed>  $meta */
    public static function source(array $meta = []): string
    {
        $request = self::request();

        return match (true) {
            in_array($meta['via'] ?? null, ['mcp', 'assistant'], true) => 'mcp',
            ($meta['via'] ?? null) === 'workflow' => 'workflow',
            $request === null => 'system',
            $request->is('api/gateway/*', 'api/daemon/*') => 'machine',
            $request->is('api/*', 'scim/*', 'oauth/token', 'oauth/revoke') => 'api',
            default => 'web',
        };
    }

    private static function request(): ?Request
    {
        if (! app()->bound('request')) {
            return null;
        }
        $request = app('request');

        return $request->route() !== null ? $request : null;
    }
}

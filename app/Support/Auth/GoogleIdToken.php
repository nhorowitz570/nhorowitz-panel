<?php

namespace App\Support\Auth;

use Firebase\JWT\JWK;
use Firebase\JWT\JWT;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Throwable;

class GoogleIdToken
{
    private const string KEYS_URL = 'https://www.googleapis.com/oauth2/v3/certs';

    private const string CACHE_KEY = 'google.id_token.keys';

    private const array ISSUERS = ['accounts.google.com', 'https://accounts.google.com'];

    /** @return array<string, mixed>|null */
    public function claims(string $token): ?array
    {
        $audience = (string) config('services.google.client_id');
        $keyId = $this->keyId($token);
        if ($audience === '' || $keyId === null) {
            return null;
        }

        $keys = $this->keys();
        if (! $this->has($keys, $keyId)) {
            $keys = $this->keys(fresh: true);
        }
        if (! $this->has($keys, $keyId)) {
            return null;
        }

        try {
            $claims = json_decode((string) json_encode(JWT::decode($token, JWK::parseKeySet(['keys' => $keys], 'RS256'))), true);
        } catch (Throwable) {
            return null;
        }

        if (! is_array($claims)
            || ($claims['aud'] ?? null) !== $audience
            || ! in_array($claims['iss'] ?? null, self::ISSUERS, true)
            || ! is_string($claims['sub'] ?? null) || $claims['sub'] === ''
            || ! is_string($claims['email'] ?? null) || $claims['email'] === '') {
            return null;
        }

        return $claims;
    }

    private function keyId(string $token): ?string
    {
        $parts = explode('.', $token);
        if (count($parts) !== 3) {
            return null;
        }

        try {
            $header = json_decode(JWT::urlsafeB64Decode($parts[0]), true);
        } catch (Throwable) {
            return null;
        }

        $keyId = is_array($header) ? ($header['kid'] ?? null) : null;

        return is_string($keyId) && $keyId !== '' && ($header['alg'] ?? null) === 'RS256' ? $keyId : null;
    }

    /**
     * @param  list<array<string, mixed>>  $keys
     */
    private function has(array $keys, string $keyId): bool
    {
        return array_any($keys, fn (array $key) => ($key['kid'] ?? null) === $keyId);
    }

    /** @return list<array<string, mixed>> */
    private function keys(bool $fresh = false): array
    {
        if ($fresh) {
            Cache::forget(self::CACHE_KEY);
        }

        $keys = Cache::get(self::CACHE_KEY);
        if (is_array($keys) && $keys !== []) {
            return array_values($keys);
        }

        try {
            $keys = Http::timeout(5)->acceptJson()->get(self::KEYS_URL)->throw()->json('keys');
        } catch (Throwable $e) {
            report($e);

            return [];
        }

        if (! is_array($keys) || $keys === []) {
            return [];
        }

        $keys = array_values(array_filter($keys, 'is_array'));
        Cache::put(self::CACHE_KEY, $keys, now()->addHour());

        return $keys;
    }
}

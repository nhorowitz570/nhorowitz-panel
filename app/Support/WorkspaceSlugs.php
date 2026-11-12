<?php

namespace App\Support;

use App\Models\Workspace;
use Closure;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class WorkspaceSlugs
{
    public const int MIN_LENGTH = 3;

    public const int MAX_LENGTH = 32;

    public const string PATTERN = '/^[a-z0-9](?:[a-z0-9-]*[a-z0-9])?$/';

    /** @var list<string> */
    public const array RESERVED = [
        'about', 'account', 'accounts', 'admin', 'administrator', 'api', 'app', 'apps',
        'assets', 'auth', 'billing', 'blog', 'cdn', 'console', 'dashboard', 'dev',
        'docs', 'email', 'feedback', 'ftp', 'help', 'home', 'imap', 'internal', 'login', 'logout',
        'mail', 'my', 'ns1', 'ns2', 'panel', 'pop', 'portal', 'register', 'root',
        'security', 'serveros', 'settings', 'shop', 'signin', 'signup', 'smtp',
        'staging', 'static', 'status', 'store', 'support', 'system', 'test', 'www',
        'workspace', 'workspaces',
    ];

    /**
     * @return array<int, mixed>
     */
    public function rules(): array
    {
        return [
            'string',
            'min:'.self::MIN_LENGTH,
            'max:'.self::MAX_LENGTH,
            'regex:'.self::PATTERN,
            Rule::notIn(self::RESERVED),
            function (string $attribute, mixed $value, Closure $fail): void {
                if (! is_string($value) || ! Workspace::where('slug', $value)->exists()) {
                    return;
                }

                $suggestions = $this->suggest($value, 2);

                $fail($suggestions->isEmpty()
                    ? 'That workspace name is taken.'
                    : 'That workspace name is taken. Try '.$suggestions->join(', ', ' or ').'.');
            },
        ];
    }

    public function normalize(string $value): string
    {
        $slug = Str::slug(Str::ascii($value));

        return trim(Str::limit($slug, self::MAX_LENGTH, ''), '-');
    }

    public function isAvailable(string $slug): bool
    {
        return $this->problemWith($slug) === null;
    }

    public function problemWith(string $slug): ?string
    {
        if (strlen($slug) < self::MIN_LENGTH || strlen($slug) > self::MAX_LENGTH) {
            return 'Use between '.self::MIN_LENGTH.' and '.self::MAX_LENGTH.' characters.';
        }

        if (! preg_match(self::PATTERN, $slug)) {
            return 'Use lowercase letters, numbers and hyphens, starting and ending with a letter or number.';
        }

        if (in_array($slug, self::RESERVED, true)) {
            return 'That name is reserved.';
        }

        if (Workspace::where('slug', $slug)->exists()) {
            return 'That workspace name is taken.';
        }

        return null;
    }

    public function generate(string $name, string $email = ''): string
    {
        $base = $this->baseFrom($name, $email);

        if ($this->isAvailable($base)) {
            return $base;
        }

        return $this->suggest($base, 1)->first() ?? $this->randomSlug();
    }

    /** @return Collection<int, string> */
    public function suggest(string $value, int $count = 3): Collection
    {
        $base = $this->normalize($value);

        if (strlen($base) < self::MIN_LENGTH) {
            $base = trim($base.'-workspace', '-');
        }

        $suggestions = collect();

        for ($attempt = 0; $attempt < 5 && $suggestions->count() < $count; $attempt++) {
            $candidates = $this->candidatesFor($base, $attempt)
                ->reject(fn (string $slug) => $suggestions->contains($slug))
                ->filter(fn (string $slug) => strlen($slug) <= self::MAX_LENGTH
                    && preg_match(self::PATTERN, $slug)
                    && ! in_array($slug, self::RESERVED, true))
                ->unique()
                ->values();

            $taken = Workspace::whereIn('slug', $candidates)->pluck('slug');

            $suggestions = $suggestions->merge($candidates->diff($taken));
        }

        return $suggestions->take($count)->values();
    }

    /** @return Collection<int, string> */
    private function candidatesFor(string $base, int $attempt): Collection
    {
        $friendly = $attempt === 0 ? ['hq', 'team', 'labs', 'ops', 'cloud'] : [];

        $numbered = collect(range(1, 4))->map(
            fn () => (string) random_int(10 ** ($attempt + 1), 10 ** ($attempt + 2) - 1),
        );

        return collect($friendly)
            ->merge($attempt === 0 ? [now()->format('Y')] : [])
            ->merge($numbered)
            ->map(fn (string $suffix) => $this->withSuffix($base, $suffix));
    }

    private function withSuffix(string $base, string $suffix): string
    {
        $room = self::MAX_LENGTH - strlen($suffix) - 1;

        return trim(substr($base, 0, $room), '-').'-'.$suffix;
    }

    private function baseFrom(string $name, string $email): string
    {
        $base = $this->normalize($name);

        if (strlen($base) < self::MIN_LENGTH) {
            $base = $this->normalize(Str::before($email, '@'));
        }

        if (strlen($base) < self::MIN_LENGTH || in_array($base, self::RESERVED, true)) {
            $base = trim($base.'-workspace', '-');
        }

        return $base;
    }

    private function randomSlug(): string
    {
        return 'workspace-'.Str::lower(Str::random(8));
    }
}

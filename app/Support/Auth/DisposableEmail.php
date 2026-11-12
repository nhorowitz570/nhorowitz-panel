<?php

namespace App\Support\Auth;

class DisposableEmail
{
    public const MAIL_SERVERS = [
        'mail.tm',
        'bumpemail.com',
        'mailinator.com',
        'guerrillamail.com',
        'yopmail.com',
        'maildrop.cc',
        '1secmail.com',
        'mailnesia.com',
        'dispostable.com',
    ];

    /** @var array<string, true>|null */
    private static ?array $domains = null;

    public function __construct(private readonly MailExchangers $exchangers) {}

    public function is(string $email): bool
    {
        $domain = strtolower(trim((string) substr(strrchr($email, '@') ?: '', 1)));
        if ($domain === '') {
            return false;
        }

        if (self::listed($domain)) {
            return true;
        }

        foreach ($this->exchangers->for($domain) as $host) {
            foreach (self::MAIL_SERVERS as $server) {
                if ($host === $server || str_ends_with($host, '.'.$server)) {
                    return true;
                }
            }
        }

        return false;
    }

    private static function listed(string $domain): bool
    {
        self::$domains ??= array_fill_keys(array_filter(array_map('trim', file(resource_path('data/disposable-email-domains.txt')) ?: []), fn (string $line) => $line !== '' && ! str_starts_with($line, '#')), true);

        $parts = explode('.', $domain);
        while (count($parts) >= 2) {
            if (isset(self::$domains[implode('.', $parts)])) {
                return true;
            }
            array_shift($parts);
        }

        return false;
    }
}

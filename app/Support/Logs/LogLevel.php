<?php

namespace App\Support\Logs;

final class LogLevel
{
    public static function detect(string $line, ?int $priority = null): string
    {
        if ($priority !== null && $priority >= 0 && $priority <= 7 && $priority !== 5 && $priority !== 6) {
            return match (true) {
                $priority <= 3 => 'error',
                $priority === 4 => 'warn',
                default => 'debug',
            };
        }

        $head = substr($line, 0, 160);

        if (preg_match('/"?(?:level|severity|lvl)"?\s*[:=]\s*"?(\w+)/i', $head, $match) === 1) {
            return self::fromWord($match[1]) ?? 'info';
        }

        $words = 'fatal|panic|crit(?:ical)?|emerg(?:ency)?|alert|error|err|severe|warn(?:ing)?|debug|trace|info|notice';

        $shouted = '/(?<![A-Za-z])('.strtoupper($words).')(?![A-Za-z])/';
        $marked = '/[\[(<]('.$words.')[\])>]|(?:^|\s)('.$words.'):|^('.$words.')\b/i';

        if (preg_match($shouted, $head, $match) === 1 || preg_match($marked, $head, $match) === 1) {
            return self::fromWord((string) end($match)) ?? 'info';
        }

        if (preg_match('/^(?:Traceback|Exception|Uncaught|PHP (?:Fatal|Parse) error)|^\s+at\s+\S+\(/', $line) === 1) {
            return 'error';
        }

        return 'info';
    }

    private static function fromWord(string $word): ?string
    {
        if (ctype_digit($word)) {
            $number = (int) $word;

            return match (true) {
                $number >= 50 => 'error',
                $number >= 40 => 'warn',
                $number >= 30 => 'info',
                $number >= 10 => 'debug',
                default => null,
            };
        }

        return match (strtolower($word)) {
            'fatal', 'panic', 'crit', 'critical', 'emerg', 'emergency', 'alert', 'error', 'err', 'severe', 'e' => 'error',
            'warn', 'warning', 'w' => 'warn',
            'debug', 'trace', 'd' => 'debug',
            'info', 'notice', 'i' => 'info',
            default => null,
        };
    }
}

<?php

namespace App\Support\StatusPages;

/** @see https://docs.discord.com/developers/link-previews/component-embeds */
final class StatusDiscordEmbed
{
    public const MAX_BYTES = 3000;

    private const ROOM = 120;

    private const DOTS = [
        StatusReport::OPERATIONAL => '🟢',
        StatusReport::DEGRADED => '🟡',
        StatusReport::DOWN => '🔴',
    ];

    /**
     * @param  array<string, mixed>  $report
     * @return array{component: array<string, mixed>}
     */
    public static function payload(array $report, string $url, string $colour): array
    {
        $uptime = $report['uptimePercent'] === null ? null
            : self::percent((float) $report['uptimePercent']).' uptime over 90 days';
        $head = '## ['.self::escape((string) $report['title']).']('.$url.")\n"
            .(self::DOTS[$report['overall']['status']] ?? '⚪').' **'.self::escape((string) $report['overall']['label']).'**'
            .($uptime !== null ? ' · '.$uptime : '');

        $top = [[
            'type' => 9,
            'components' => [['type' => 10, 'content' => $head]],
            'accessory' => ['type' => 2, 'style' => 5, 'url' => $url, 'label' => 'Open status page'],
        ]];
        if (is_string($report['notice']['text'] ?? null) && $report['notice']['text'] !== '') {
            $top[] = ['type' => 10, 'content' => '> '.self::escape(mb_strimwidth($report['notice']['text'], 0, 200, '…'))];
        }

        $updated = is_string($report['updatedAt'] ?? null) ? strtotime($report['updatedAt']) : false;
        $footer = ['type' => 10, 'content' => '-# '.($updated ? "Updated <t:{$updated}:R> · " : '').'ServerOS'];

        $lines = [];
        $total = 0;
        foreach ($report['sections'] as $section) {
            $first = true;
            foreach ($section['components'] as $component) {
                $total++;
                $line = ($first && $section['name'] !== null ? '**'.self::escape((string) $section['name'])."**\n" : '')
                    .(self::DOTS[$component['status']] ?? '⚪').' '.self::escape((string) $component['label'])
                    .(isset($component['uptime']['percent']) ? ' · '.self::percent((float) $component['uptime']['percent']) : '');

                $trial = self::build($top, [...$lines, $line], $footer, $colour);
                if (strlen(self::encode($trial)) > self::MAX_BYTES - self::ROOM) {
                    break 2;
                }
                $lines[] = $line;
                $first = false;
            }
        }
        if (count($lines) < $total) {
            $lines[] = '-# and '.($total - count($lines)).' more on the page';
        }

        return self::build($top, $lines, $footer, $colour);
    }

    /** @param  array{component: array<string, mixed>}  $payload */
    public static function encode(array $payload): string
    {
        return (string) json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP);
    }

    /**
     * @param  list<array<string, mixed>>  $top
     * @param  list<string>  $lines
     * @param  array<string, mixed>  $footer
     * @return array{component: array<string, mixed>}
     */
    private static function build(array $top, array $lines, array $footer, string $colour): array
    {
        $components = $top;
        if ($lines !== []) {
            $components[] = ['type' => 14, 'spacing' => 1];
            $components[] = ['type' => 10, 'content' => implode("\n", $lines)];
        }
        $components[] = $footer;

        return ['component' => [
            'type' => 17,
            'accent_color' => (int) hexdec(ltrim($colour, '#')),
            'components' => $components,
        ]];
    }

    private static function percent(float $value): string
    {
        return rtrim(rtrim(number_format($value, 2), '0'), '.').'%';
    }

    private static function escape(string $text): string
    {
        return (string) preg_replace('/([\\\\*_~`|\[\]()])/u', '\\\\$1', $text);
    }
}

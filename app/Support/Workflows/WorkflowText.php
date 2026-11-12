<?php

namespace App\Support\Workflows;

use App\Models\WorkflowRun;

final class WorkflowText
{
    private const PLACEHOLDER = '/\{\{\s*([a-z_]+(?:\.[A-Za-z0-9_.-]+)?)\s*\}\}/';

    public static function render(string $text, WorkflowRun $run, bool $encode = false): string
    {
        $lookup = self::lookup($run);

        return preg_replace_callback(self::PLACEHOLDER, function (array $m) use ($lookup, $encode) {
            $value = $lookup($m[1]);

            return $encode ? rawurlencode($value) : $value;
        }, $text) ?? $text;
    }

    /** @return array{0: string, 1: array<string, string>} */
    public static function shell(string $text, WorkflowRun $run): array
    {
        $lookup = self::lookup($run);
        preg_match_all(self::PLACEHOLDER, $text, $matches, PREG_OFFSET_CAPTURE | PREG_SET_ORDER);
        $at = [];
        foreach ($matches as $m) {
            $at[$m[0][1]] = [strlen($m[0][0]), $m[1][0]];
        }

        $env = $names = [];
        $out = '';
        $state = null;
        $length = strlen($text);
        for ($i = 0; $i < $length; $i++) {
            if (isset($at[$i])) {
                [$size, $key] = $at[$i];
                if (! isset($names[$key])) {
                    $names[$key] = 'SERVEROS_V'.(count($names) + 1);
                    $env[$names[$key]] = $lookup($key);
                }
                $out .= $state === "'" ? "'\"\${{$names[$key]}}\"'" : "\${{$names[$key]}}";
                $i += $size - 1;

                continue;
            }
            $c = $text[$i];
            $out .= $c;
            if ($state === '#') {
                $state = $c === "\n" ? null : $state;
            } elseif ($state === "'") {
                $state = $c === "'" ? null : $state;
            } elseif ($c === '\\' && $i + 1 < $length && ! isset($at[$i + 1])) {
                $out .= $text[++$i];
            } elseif ($state === '"') {
                $state = $c === '"' ? null : $state;
            } elseif ($c === "'" || $c === '"') {
                $state = $c;
            } elseif ($c === '#' && ($i === 0 || str_contains(" \t\n;&|(", $text[$i - 1]))) {
                $state = '#';
            }
        }

        return [$out, $env];
    }

    /** @return callable(string): string */
    private static function lookup(WorkflowRun $run): callable
    {
        $data = $run->trigger['data'] ?? [];
        $workflow = $run->workflow;
        $values = [
            'workflow' => $workflow->name,
            'trigger' => $run->trigger['summary'] ?? '',
            'machine' => (string) ($data['machine'] ?? ''),
            'service' => (string) ($data['service'] ?? ''),
            'run_url' => 'https://'.$workflow->workspace->host()."/workflows/{$workflow->id}?run={$run->id}",
        ];
        $agent = self::lastAgent($run);
        $values['agent.summary'] = $agent['summary'] ?? '';
        $values['agent.pr'] = $agent['pr'] ?? '';

        return function (string $key) use ($values, $data): string {
            $value = str_starts_with($key, 'data.') ? data_get($data, substr($key, 5)) : ($values[$key] ?? null);
            if ($value === null) {
                return '';
            }

            return is_scalar($value) ? (string) $value : (json_encode($value) ?: '');
        };
    }

    /** @return array{summary?: string, pr?: string|null} */
    private static function lastAgent(WorkflowRun $run): array
    {
        $agents = collect($run->graph['nodes'] ?? [])->where('kind', 'agent')->pluck('id')->all();

        return collect($run->steps ?? [])
            ->only($agents)
            ->filter(fn (array $step) => isset($step['finishedAt']))
            ->sortBy('finishedAt')
            ->last() ?? [];
    }

    public static function kindLabel(string $kind): string
    {
        return [
            'trigger' => 'Trigger',
            'redeploy' => 'Redeploy',
            'release' => 'Release app',
            'restart' => 'Restart service',
            'start' => 'Start service',
            'stop' => 'Stop service',
            'command' => 'Run command',
            'agent' => 'Run agent',
            'backup' => 'Back up',
            'copy_data' => 'Copy data',
            'promote' => 'Promote',
            'check_monitor' => 'Check monitor',
            'http' => 'HTTP request',
            'alert' => 'Send alert',
            'wait' => 'Wait',
            'branch' => 'If',
        ][$kind] ?? $kind;
    }
}

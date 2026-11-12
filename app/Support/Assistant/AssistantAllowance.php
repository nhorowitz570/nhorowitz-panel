<?php

namespace App\Support\Assistant;

use App\Models\Workspace;
use App\Support\Ai\AiProviders;
use Illuminate\Support\Facades\RateLimiter;

final class AssistantAllowance
{
    public const PER_HOUR = 30;

    public const PER_HOUR_OWN_KEY = 150;

    public const MAX_TURNS = 12;

    public const MAX_TOOL_CALLS = 20;

    /** @return array{used: int, limit: int, ownKey: bool, resetsIn: int|null} */
    public static function for(Workspace $workspace): array
    {
        $ownKey = app(AiProviders::class)->forAgent($workspace)['own'];
        $limit = $ownKey ? self::PER_HOUR_OWN_KEY : self::PER_HOUR;
        $key = self::key($workspace);
        $used = min($limit, RateLimiter::attempts($key));

        return ['used' => $used, 'limit' => $limit, 'ownKey' => $ownKey, 'resetsIn' => $used > 0 ? RateLimiter::availableIn($key) : null];
    }

    public static function take(Workspace $workspace): ?string
    {
        $allowance = self::for($workspace);
        if ($allowance['used'] >= $allowance['limit']) {
            $minutes = max(1, (int) ceil(($allowance['resetsIn'] ?? 60) / 60));

            return "You’ve used this hour’s {$allowance['limit']} messages to Ask AI. You can ask again in {$minutes} ".($minutes === 1 ? 'minute' : 'minutes').'.'
                .($allowance['ownKey'] ? '' : ' Connect your own AI key in Settings › AI and MCP for '.self::PER_HOUR_OWN_KEY.' an hour.');
        }
        RateLimiter::hit(self::key($workspace), 3600);

        return null;
    }

    private static function key(Workspace $workspace): string
    {
        return "assistant:hour:{$workspace->id}";
    }
}

<?php

namespace App\Support\Fixes;

use App\Models\AgentFix;
use App\Models\Workspace;
use App\Support\Ai\AiProviders;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\RateLimiter;

final class FixAllowance
{
    public const PER_HOUR = 20;

    public const PER_HOUR_OWN_KEY = 100;

    public const BURST = 5;

    public const BURST_SECONDS = 60;

    /**
     * @return array{used: int, limit: int, ownKey: bool, ownKeyLimit: int, resetsAt: string|null}
     */
    public static function for(Workspace $workspace): array
    {
        $ownKey = app(AiProviders::class)->forAgent($workspace)['own'];
        $limit = $ownKey ? self::PER_HOUR_OWN_KEY : self::PER_HOUR;
        $since = now()->subHour();
        $used = AgentFix::query()->where('workspace_id', $workspace->id)->where('created_at', '>', $since)->count();

        $resetsAt = null;
        if ($used >= $limit) {
            $oldest = AgentFix::query()->where('workspace_id', $workspace->id)->where('created_at', '>', $since)
                ->orderBy('created_at')->skip($used - $limit)->value('created_at');
            $resetsAt = $oldest !== null ? Carbon::parse($oldest)->addHour()->toIso8601String() : null;
        }

        return ['used' => $used, 'limit' => $limit, 'ownKey' => $ownKey, 'ownKeyLimit' => self::PER_HOUR_OWN_KEY, 'resetsAt' => $resetsAt];
    }

    public static function refuse(Workspace $workspace): ?string
    {
        $allowance = self::for($workspace);
        if ($allowance['used'] >= $allowance['limit']) {
            $at = $allowance['resetsAt'] !== null ? Carbon::parse($allowance['resetsAt'])->format('H:i') : null;

            return "You’ve used this hour’s {$allowance['limit']} investigations."
                .($at !== null ? " You can ask again at {$at} UTC." : ' You can ask again soon.')
                .($allowance['ownKey'] ? '' : ' Connect your own AI key in Settings › AI and MCP for '.self::PER_HOUR_OWN_KEY.' an hour.');
        }

        $key = "fixes:start:{$workspace->id}";
        if (RateLimiter::tooManyAttempts($key, self::BURST)) {
            $wait = max(1, RateLimiter::availableIn($key));

            return "That’s a lot at once. You can ask again in {$wait} ".($wait === 1 ? 'second' : 'seconds').'.';
        }
        RateLimiter::hit($key, self::BURST_SECONDS);

        return null;
    }
}

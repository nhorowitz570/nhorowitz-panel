<?php

namespace App\Mcp\Tools\Machine;

use App\Mcp\McpContext;
use App\Mcp\Schema;
use App\Mcp\ToolError;
use App\Models\DaemonJob;
use App\Models\Machine;
use App\Support\DaemonJobQueue;
use App\Support\Machines\PackageUpdates;
use Closure;
use Illuminate\Support\Facades\RateLimiter;
use RuntimeException;

final class ServerWork
{
    public function __construct(private readonly DaemonJobQueue $queue) {}

    /** @param  array<string, mixed>  $job */
    public function queue(McpContext $context, Machine $machine, string $kind, array $job, int $timeout): DaemonJob
    {
        if (($reason = $this->queue->unavailable($machine)) !== null) {
            throw new ToolError($reason);
        }

        try {
            return $context->within(fn () => $this->queue->queue($machine, $context->user, $kind, $job, null, $timeout));
        } catch (RuntimeException $e) {
            throw new ToolError($e->getMessage());
        }
    }

    public function unavailable(Machine $machine): ?string
    {
        return $this->queue->unavailable($machine);
    }

    public static function installing(Machine $machine): ?DaemonJob
    {
        return PackageUpdates::installing($machine);
    }

    /**
     * @param  array<string, mixed>  $arguments
     * @return array<string, mixed>
     */
    public static function trimmed(array $arguments, string ...$keys): array
    {
        foreach ($keys as $key) {
            if (is_string($arguments[$key] ?? null)) {
                $arguments[$key] = trim($arguments[$key]);
            }
        }

        return $arguments;
    }

    public static function throttle(McpContext $context, string $action, int $perMinute): void
    {
        $key = "mcp-machine:{$action}:{$context->user->id}";
        if (RateLimiter::tooManyAttempts($key, $perMinute)) {
            $seconds = max(1, RateLimiter::availableIn($key));

            throw new ToolError("That’s been done {$perMinute} times in the last minute, as often as the dashboard allows. Try again in {$seconds} seconds.");
        }
        RateLimiter::hit($key, 60);
    }

    /** @param  array<string, mixed>  $arguments */
    public static function confirm(array $arguments, string $name, string $what, string $warning = 'This can’t be undone.'): void
    {
        $given = $arguments['confirm'] ?? null;
        if (! is_string($given) || trim($given) === '') {
            throw new ToolError("{$warning} Check with the person first, then call again with confirm set to the {$what}’s name exactly: “{$name}”.");
        }
        if (trim($given) !== $name) {
            throw new ToolError("confirm doesn’t match. It must be the {$what}’s name exactly: “{$name}”. Nothing was changed.");
        }
    }

    /** @return Closure(string, mixed, Closure): void */
    public static function reference(): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail): void {
            if (! (is_string($value) && trim($value) !== '') && ! is_int($value)) {
                $fail("The {$attribute} must be an id or a name.");
            }
        };
    }

    /** @return array<string, mixed> */
    public static function waitSchema(): array
    {
        return Schema::integer('Seconds to wait for the job to finish before answering (0–25, default 0). Jobs that take longer keep running: follow them with get_job.', 0, McpContext::MAX_WAIT_SECONDS);
    }

    /** @return list<string> */
    public static function waitRules(): array
    {
        return ['sometimes', 'integer', 'min:0', 'max:'.McpContext::MAX_WAIT_SECONDS];
    }
}

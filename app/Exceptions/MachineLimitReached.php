<?php

namespace App\Exceptions;

use App\Models\Workspace;
use App\Support\Edition;
use RuntimeException;

class MachineLimitReached extends RuntimeException
{
    public static function for(Workspace $workspace): self
    {
        $limit = $workspace->machineLimit();

        return new self($limit === 0
            ? "Workspace [{$workspace->slug}] needs a plan before connecting machines."
            : "Workspace [{$workspace->slug}] has reached its limit of {$limit} machines.");
    }

    public static function selfHostedMessage(Workspace $workspace): ?string
    {
        if (! Edition::selfHosted()) {
            return null;
        }
        $message = __('This install allows :count machines per workspace. Remove one, or ask whoever runs this install to raise SERVEROS_SELF_HOSTED_MACHINES.', ['count' => (int) $workspace->machineLimit()]);

        return is_string($message) ? $message : null;
    }
}

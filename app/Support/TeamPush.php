<?php

namespace App\Support;

use App\Jobs\SendNtfy;
use Illuminate\Support\Facades\Route;

class TeamPush
{
    public static function send(string $title, string $message, ?string $url = null, string $tag = 'bell'): void
    {
        if (blank(config('services.ntfy.topic'))) {
            return;
        }

        SendNtfy::dispatch($title, $message, $url, $tag)->afterCommit();
    }

    public static function admin(string $route): ?string
    {
        return Route::has($route) ? route($route) : null;
    }
}

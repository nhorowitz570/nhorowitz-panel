<?php

namespace App\Mcp\Tools;

use App\Mcp\ToolGroup;
use App\Mcp\Tools\Log\GetLogStats;
use App\Mcp\Tools\Log\SearchLogs;

final class LogTools implements ToolGroup
{
    public static function tools(): array
    {
        return [
            SearchLogs::class,
            GetLogStats::class,
        ];
    }
}

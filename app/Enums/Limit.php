<?php

namespace App\Enums;

enum Limit: string
{
    case Machines = 'machines';

    case McpCalls = 'mcp_calls';

    case LogRetentionDays = 'log_retention_days';

    case LogLinesPerDay = 'log_lines_per_day';

    case ActivityRetentionDays = 'activity_retention_days';

    case Monitors = 'monitors';

    case MinMonitorInterval = 'min_monitor_interval';

    case StatusSubscribers = 'status_subscribers';

    case ApiRequestsPerMinute = 'api_requests_per_minute';
}

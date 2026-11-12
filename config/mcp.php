<?php

use App\Mcp\Tools;

return [
    'groups' => [
        Tools\WorkspaceTools::class,
        Tools\MachineTools::class,
        Tools\ServiceTools::class,
        Tools\DeployTools::class,
        Tools\ProjectTools::class,
        Tools\MonitorTools::class,
        Tools\StatusPageTools::class,
        Tools\WorkflowTools::class,
        Tools\BackupTools::class,
        Tools\LogTools::class,
        Tools\TeamTools::class,
    ],

    'rate_limit' => (int) env('MCP_RATE_LIMIT', 120),
];

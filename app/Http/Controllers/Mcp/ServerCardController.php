<?php

namespace App\Http\Controllers\Mcp;

use App\Http\Controllers\Controller;
use App\Mcp\McpServer;
use App\Mcp\Tool;
use App\Mcp\ToolRegistry;
use Illuminate\Http\JsonResponse;

class ServerCardController extends Controller
{
    public function workspace(ToolRegistry $registry): JsonResponse
    {
        $domain = (string) config('app.domain');
        $site = rtrim((string) config('app.url'), '/');
        $tools = array_values(array_map(fn (Tool $tool) => $tool->definition(), $registry->all()));

        return self::card([
            'name' => McpServer::NAME,
            'title' => 'ServerOS',
            'version' => McpServer::VERSION,
            'description' => 'Run the servers you already own: machines, the apps and services on them, deploys, logs, uptime monitors, status pages, backups, OS updates and workflows. Each workspace has its own server, signed in with OAuth.',
            'websiteUrl' => $site,
            'documentationUrl' => 'https://docs.'.$domain.'/guides/ai-apps',
            'serverInfo' => ['name' => McpServer::NAME, 'title' => 'ServerOS', 'version' => McpServer::VERSION],
            'remotes' => [[
                'type' => 'streamable-http',
                'url' => 'https://{workspace}.'.$domain.'/mcp',
                'variables' => [
                    'workspace' => [
                        'description' => 'Your workspace’s name, as in its address: acme for acme.'.$domain.'.',
                        'isRequired' => true,
                    ],
                ],
                'supportedProtocolVersions' => McpServer::VERSIONS,
            ]],
            'capabilities' => ['tools' => ['listChanged' => false]],
            'authentication' => [
                'required' => true,
                'schemes' => ['oauth2'],
                'authorizationServer' => $site,
                'metadata' => $site.'/.well-known/oauth-authorization-server',
            ],
            'tools' => $tools,
        ]);
    }

    /** @param  array<string, mixed>  $card */
    public static function card(array $card): JsonResponse
    {
        return response()->json($card, 200, [
            'Access-Control-Allow-Origin' => '*',
            'Cache-Control' => 'public, max-age=3600',
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }
}

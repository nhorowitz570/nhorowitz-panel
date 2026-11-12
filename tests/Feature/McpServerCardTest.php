<?php

use App\Mcp\ToolRegistry;

test('serveros.com has an MCP server card listing every tool of the workspace server', function () {
    $count = count(app(ToolRegistry::class)->all());

    $this->getJson('https://'.config('app.domain').'/.well-known/mcp/server-card.json')
        ->assertOk()
        ->assertHeader('Access-Control-Allow-Origin', '*')
        ->assertJsonPath('name', 'serveros')
        ->assertJsonPath('remotes.0.url', 'https://{workspace}.'.config('app.domain').'/mcp')
        ->assertJsonPath('authentication.required', true)
        ->assertJsonCount($count, 'tools')
        ->assertJsonStructure(['tools' => [['name', 'title', 'description', 'inputSchema', 'annotations']]]);
});

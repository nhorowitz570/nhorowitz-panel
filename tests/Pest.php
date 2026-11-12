<?php

use App\Mcp\Scopes;
use App\Models\McpToken;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceLogStore;
use App\Support\Admin\Impersonation;
use App\Support\Logs\LogStore;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Illuminate\Testing\TestResponse;
use Inertia\Inertia;
use Tests\TestCase;

pest()->extend(TestCase::class)
    ->use(RefreshDatabase::class)
    ->in('Feature', '../vendor/serveroshq/serveros.com/tests/Feature', '../vendor/serveroshq/panel-ee/tests/Feature');

expect()->extend('toBeOne', function () {
    return $this->toBe(1);
});

function something() {}

/**
 * @param  list<string>  $scopes
 */
function mcpKey(Workspace $workspace, ?User $user = null, array $scopes = Scopes::ALL): string
{
    return McpToken::issueKey($workspace, $user ?? $workspace->owner, 'Test key', $scopes)[1];
}

/** @param  array<string, mixed>  $params */
function mcpRequest(Workspace $workspace, string $key, string $method, array $params = [], int|string $id = 1): TestResponse
{
    return test()->withHeaders(['Authorization' => "Bearer {$key}"])
        ->postJson('https://'.$workspace->host().'/mcp', ['jsonrpc' => '2.0', 'id' => $id, 'method' => $method, 'params' => (object) $params]);
}

/**
 * @param  array<string, mixed>  $arguments
 * @return array{data: mixed, error: bool, text: string}
 */
function mcpCall(Workspace $workspace, string $key, string $tool, array $arguments = []): array
{
    $response = mcpRequest($workspace, $key, 'tools/call', ['name' => $tool, 'arguments' => (object) $arguments])->assertOk();
    $result = $response->json('result');
    if ($result === null) {
        throw new RuntimeException('JSON-RPC error: '.json_encode($response->json('error')));
    }

    return [
        'data' => $result['structuredContent'] ?? json_decode($result['content'][0]['text'] ?? 'null', true),
        'error' => (bool) ($result['isError'] ?? false),
        'text' => $result['content'][0]['text'] ?? '',
    ];
}

function ownLogStore(Workspace $workspace, array $attributes = []): WorkspaceLogStore
{
    $path = tempnam(sys_get_temp_dir(), 'serveros-logstore-');
    $store = WorkspaceLogStore::query()->forceCreate([
        'workspace_id' => $workspace->id, 'driver' => 'sqlite', 'host' => 'db.example.com', 'port' => 5432,
        'database' => $path, 'username' => 'serveros', 'password' => 'secret', 'enabled' => true, ...$attributes,
    ]);
    LogStore::install($store);
    LogStore::flush();

    return $store;
}

function viewAsUrl(User $user): string
{
    if (! Route::has('test.view-as')) {
        Route::post('test/view-as/{user}', function (Request $request, User $user) {
            $reason = trim((string) $request->validate(['reason' => ['required', 'string', 'max:500']])['reason']);
            Impersonation::start($request, $request->user(), $user, $reason);

            return Inertia::location(route('dashboard'));
        })->middleware(['web', 'auth', 'verified', 'can:viewAdmin'])->name('test.view-as');
        app('router')->getRoutes()->refreshNameLookups();
    }

    return '/test/view-as/'.$user->id;
}

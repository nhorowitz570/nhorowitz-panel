<?php

namespace App\Http\Controllers\Api\V1;

use App\Api\ApiRoutes;
use App\Api\ApiRunner;
use App\Api\OpenApi;
use App\Http\Controllers\Controller;
use App\Mcp\McpContext;
use App\Mcp\ToolRegistry;
use App\Support\Docs\DocsUrl;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ApiController extends Controller
{
    public function __construct(
        private readonly ToolRegistry $tools,
        private readonly ApiRunner $runner,
    ) {}

    public function route(Request $request): JsonResponse
    {
        $name = (string) $request->route()?->defaults['tool'];
        $tool = $this->tools->find($name) ?? abort(404);

        $body = $this->body($request);
        if ($body instanceof JsonResponse) {
            return $body;
        }
        $path = array_map(fn ($v) => is_string($v) ? rawurldecode($v) : $v, array_diff_key($request->route()?->parameters() ?? [], ['tool' => true]));
        $arguments = ApiRunner::coerce($tool, [...$request->query(), ...$body, ...$path]);

        return $this->answer($this->runner->run($this->context($request), $tool, $arguments));
    }

    public function tools(Request $request): JsonResponse
    {
        $context = $this->context($request);
        $routes = collect(ApiRoutes::all())->groupBy('tool');

        return response()->json(['data' => array_values(array_map(fn ($tool) => [
            'name' => $tool->name(),
            'title' => $tool->title(),
            'description' => $tool->description(),
            'scope' => $tool->scope(),
            'destructive' => $tool->destructive(),
            'allowed' => $this->tools->denial($tool, $context) === null,
            'routes' => ($routes[$tool->name()] ?? collect())->map(fn ($r) => "{$r['method']} /v1/{$r['path']}")->values(),
            'inputSchema' => $tool->inputSchema(),
        ], $this->tools->all()))], 200, [], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    public function tool(Request $request, string $name): JsonResponse
    {
        $tool = $this->tools->find($name);
        if ($tool === null) {
            return $this->answer(ApiRunner::error(404, 'not_found', "There’s no tool called {$name}. GET /v1/tools lists them."));
        }
        $body = $this->body($request);
        if ($body instanceof JsonResponse) {
            return $body;
        }

        return $this->answer($this->runner->run($this->context($request), $tool, $body));
    }

    public function index(Request $request): JsonResponse
    {
        $context = $this->context($request);

        return response()->json(['data' => [
            'workspace' => ['id' => $context->workspace->id, 'name' => $context->workspace->name, 'slug' => $context->workspace->slug],
            'user' => ['id' => $context->user->id, 'name' => $context->user->name, 'email' => $context->user->email],
            'key' => ['name' => $context->token?->name, 'scopes' => $context->scopes()],
            'docs' => DocsUrl::page('guides', 'api'),
            'openapi' => url('/api/v1/openapi.json'),
        ]], 200, [], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    public function openapi(): JsonResponse
    {
        return response()->json(OpenApi::document($this->tools), 200, ['Access-Control-Allow-Origin' => '*'], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    private function context(Request $request): McpContext
    {
        return $request->attributes->get('api.context');
    }

    /** @return array<string, mixed>|JsonResponse */
    private function body(Request $request): array|JsonResponse
    {
        $raw = $request->getContent();
        if (trim($raw) === '') {
            return [];
        }
        $body = json_decode($raw, true);
        if (! is_array($body) || (array_is_list($body) && $body !== [])) {
            return $this->answer(ApiRunner::error(400, 'invalid_json', 'Send a JSON object as the body, with Content-Type: application/json.'));
        }

        return $body;
    }

    /** @param  array{0: int, 1: array<string, mixed>}  $answer */
    private function answer(array $answer): JsonResponse
    {
        return response()->json($answer[1], $answer[0], [], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }
}

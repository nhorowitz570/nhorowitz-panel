<?php

namespace App\Support\Audit;

use App\Mcp\Scopes;
use App\Mcp\Tool;
use Illuminate\Http\Request;
use Illuminate\Routing\Route;
use ReflectionClass;
use ReflectionMethod;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Response;

final class AuditCoverage
{
    private const array ALSO = ['invitations.accept', 'mcp.oauth.approve', 'workspaces.store'];

    private const string MISSED_TOOLS = 'audit.missed_tools';

    public static function inScope(Route $route): bool
    {
        $action = $route->getActionName();
        if (array_diff($route->methods(), ['GET', 'HEAD', 'OPTIONS']) === [] || ! (str_starts_with($action, 'App\\') || str_starts_with($action, 'ServerOS\\'))) {
            return false;
        }
        $name = (string) $route->getName();

        return str_starts_with($name, 'workspace.') || in_array($name, self::ALSO, true)
            || str_starts_with($route->uri(), 'admin/') || str_starts_with($route->uri(), 'settings/');
    }

    public static function declaration(Route $route): Audited|NotAudited|null
    {
        $action = $route->getActionName();
        [$class, $method] = str_contains($action, '@') ? explode('@', $action, 2) : [$action, '__invoke'];
        if (! class_exists($class) || ! method_exists($class, $method)) {
            return null;
        }

        foreach ([new ReflectionMethod($class, $method), new ReflectionClass($class)] as $target) {
            foreach ([Audited::class, NotAudited::class] as $attribute) {
                if (($found = $target->getAttributes($attribute)) !== []) {
                    return $found[0]->newInstance();
                }
            }
        }

        return null;
    }

    public static function missedRecording(Request $request, Response $response): ?string
    {
        $route = $request->route();
        if (! $route instanceof Route || ! self::inScope($route) || ! self::declaration($route) instanceof Audited
            || ! self::wroteSilently($request, $response) || self::turnedAway($response)) {
            return null;
        }

        return "{$request->method()} {$route->uri()} ({$route->getActionName()}) is #[Audited] but recorded nothing. Record it, call AuditContext::nothingChanged() when there was nothing to record, or mark it #[NotAudited('why')].";
    }

    public static function wroteSilently(Request $request, Response $response): bool
    {
        $status = $response->getStatusCode();
        $succeeded = $status < 400 || ($status === 409 && $response->headers->has('X-Inertia-Location'));

        return ! $request->isMethod('GET') && ! $request->isMethod('HEAD') && $succeeded
            && ! ($request->hasSession() && $request->session()->has('errors'))
            && ! AuditContext::recorded($request);
    }

    public static function toolDeclaration(Tool $tool): Audited|NotAudited|null
    {
        if ($tool->scope() === Scopes::READ) {
            return null;
        }
        foreach ([new ReflectionMethod($tool, 'handle'), new ReflectionClass($tool)] as $target) {
            if (($found = $target->getAttributes(NotAudited::class)) !== []) {
                return $found[0]->newInstance();
            }
        }

        return new Audited;
    }

    public static function afterTool(Tool $tool): void
    {
        $request = request();
        if (! self::toolDeclaration($tool) instanceof Audited || AuditContext::recorded($request)) {
            return;
        }

        $missed = $request->attributes->get(self::MISSED_TOOLS, []);
        $missed[] = "The MCP tool {$tool->name()} (".$tool::class.') changed something but recorded nothing. Record it with $context->record(), call AuditContext::nothingChanged() when there was nothing to record, or mark handle() #[NotAudited(\'why\')].';
        $request->attributes->set(self::MISSED_TOOLS, $missed);
    }

    /** @return list<string> */
    public static function missedToolRecordings(Request $request): array
    {
        return $request->attributes->get(self::MISSED_TOOLS, []);
    }

    private static function turnedAway(Response $response): bool
    {
        return $response instanceof RedirectResponse && in_array(strtok($response->getTargetUrl(), '?'), [
            route('login'),
            route('verification.notice'),
            route('password.confirm'),
        ], true);
    }
}

<?php

namespace App\Http\Controllers;

use App\Models\StatusPage;
use App\Models\Workspace;
use App\Models\WorkspaceStatusPage;
use App\Support\Extensions\Extensions;
use App\Support\StatusPages\FleetStatusReport;
use App\Support\StatusPages\StatusDiscordEmbed;
use App\Support\StatusPages\StatusOgImage;
use App\Support\StatusPages\StatusReport;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response as HttpResponse;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;

/** @phpstan-import-type Report from StatusReport */
class PublicStatusController extends Controller
{
    public function show(Request $request, string $slug): Response|SymfonyResponse
    {
        $page = $this->find($request, $slug);
        if ($page instanceof SymfonyResponse) {
            return $page;
        }

        $workspace = self::workspaceOf($page);
        $report = self::report($page);

        $response = Inertia::render('status/Show', [
            'status' => $report,
            'meta' => $page->public ? self::meta($page, $report) : null,
            'statusBrand' => ['name' => $workspace->name, 'logoUrl' => $page->iconUrl() ?? $workspace->logoUrl()],
            'pageTitle' => $page->page_title,
            'favicon' => $page->faviconUrl(),
            'private' => ! $page->public,
            'jsonUrl' => self::link($page, '/status.json'),
            'subscribeUrl' => $page->public ? self::link($page, '/subscribe') : null,
            'feedUrl' => $page->public ? self::link($page, '/feed.atom') : null,
            'poweredByUrl' => app(Extensions::class)->poweredByUrl($workspace),
        ])->toResponse($request);
        $response->headers->set('Cache-Control', $page->public ? 'public, max-age=30' : 'private, no-store');

        return $response;
    }

    public function embed(Request $request, string $slug): SymfonyResponse
    {
        $page = StatusPage::query()->where('slug', $slug)->with(['machine.workspace', 'machine.serviceGroups'])->first()
            ?? WorkspaceStatusPage::query()->where('slug', $slug)->with('workspace')->first();
        abort_if($page === null || ! $page->live() || ! $page->public, 404);
        self::abortUnlessServedHere($request, $page);

        $view = $request->query('view') === 'bar' ? 'bar' : 'card';
        $theme = in_array($request->query('theme'), ['dark', 'auto'], true) ? $request->query('theme') : 'light';

        $response = Inertia::render('status/Embed', [
            'status' => self::report($page),
            'view' => $view,
            'theme' => $theme,
            'pageUrl' => self::link($page),
            'poweredByUrl' => app(Extensions::class)->poweredByUrl(self::workspaceOf($page)),
        ])->toResponse($request);
        $response->headers->set('Cache-Control', 'public, max-age=30');
        $response->headers->set('Content-Security-Policy', 'frame-ancestors *');

        return $response;
    }

    public function og(Request $request, string $slug): HttpResponse
    {
        $page = StatusPage::query()->where('slug', $slug)->with(['machine.workspace', 'machine.serviceGroups'])->first()
            ?? WorkspaceStatusPage::query()->where('slug', $slug)->with('workspace')->first();
        abort_if($page === null || ! $page->live() || ! $page->public, 404);
        self::abortUnlessServedHere($request, $page);

        $report = self::report($page);
        $address = preg_replace('#^https?://#', '', self::link($page));
        $png = Cache::remember('status-og:'.$page->slug.':'.self::version($report), now()->addMinutes(10), fn () => StatusOgImage::png($report, (string) $address));

        return response($png, 200, [
            'Content-Type' => 'image/png',
            'Cache-Control' => 'public, max-age=300',
        ]);
    }

    public function json(Request $request, string $slug): JsonResponse|SymfonyResponse
    {
        $page = $this->find($request, $slug);
        if ($page instanceof SymfonyResponse) {
            return $page;
        }

        $report = self::report($page);

        return response()->json([
            'title' => $report['title'],
            'status' => $report['overall'],
            'notice' => $report['notice'],
            'uptime_percent' => $report['uptimePercent'],
            'updated_at' => $report['updatedAt'],
            'components' => collect($report['sections'])->flatMap(fn (array $section) => array_map(fn (array $c) => [
                'name' => $c['label'],
                'group' => $section['name'],
                'status' => $c['status'],
                'uptime_percent' => $c['uptime']['percent'] ?? null,
                'response_ms' => $c['responseMs'] ?? null,
            ], $section['components']))->values(),
            'incidents' => array_map(fn (array $i) => [
                'name' => $i['label'],
                'started_at' => $i['startedAt'],
                'resolved_at' => $i['resolvedAt'],
                'seconds' => $i['seconds'],
            ], $report['incidents'] ?? []),
            'notices' => array_map(fn (array $n) => [
                'title' => $n['title'],
                'impact' => $n['impact'],
                'state' => $n['state'],
                'machine' => $n['machine'],
                'started_at' => $n['startedAt'],
                'resolved_at' => $n['resolvedAt'],
                'updates' => $n['updates'],
            ], $report['notices']),
            'scheduled_maintenance' => array_map(fn (array $m) => [
                'starts_at' => $m['startsAt'],
                'ends_at' => $m['endsAt'],
                'reason' => $m['reason'],
                'machine' => $m['machine'],
            ], $report['upcoming']),
        ])->header('Cache-Control', $page->public ? 'public, max-age=30' : 'private, no-store')
            ->header('Access-Control-Allow-Origin', $page->public ? '*' : '');
    }

    public function badge(Request $request, string $slug): HttpResponse
    {
        $page = StatusPage::query()->where('slug', $slug)->with('machine.workspace')->first()
            ?? WorkspaceStatusPage::query()->where('slug', $slug)->with('workspace')->first();
        abort_if($page === null || ! $page->live() || ! $page->public, 404);
        self::abortUnlessServedHere($request, $page);

        $overall = self::report($page)['overall'];
        [$label, $color] = match ($overall['status']) {
            StatusReport::OPERATIONAL => ['operational', '#2f9e44'],
            StatusReport::DEGRADED => ['degraded', '#e8a317'],
            StatusReport::DOWN => [$overall['label'] === 'Major outage' ? 'major outage' : 'partial outage', '#e03131'],
            default => ['unknown', '#868e96'],
        };

        return response(self::badgeSvg('status', $label, $color), 200, [
            'Content-Type' => 'image/svg+xml',
            'Cache-Control' => 'public, max-age=60',
        ]);
    }

    private function find(Request $request, string $slug): StatusPage|WorkspaceStatusPage|SymfonyResponse
    {
        $page = StatusPage::query()->where('slug', $slug)->with(['machine.workspace', 'machine.serviceGroups'])->first()
            ?? WorkspaceStatusPage::query()->where('slug', $slug)->with('workspace')->first();
        abort_if($page === null || ! $page->live(), 404);
        self::abortUnlessServedHere($request, $page);

        if (! $page->public) {
            $user = $request->user();
            if ($user === null) {
                if ($request->attributes->get('status_base') === '') {
                    return response()->view('status-private', [
                        'workspace' => self::workspaceOf($page)->name,
                        'signIn' => rtrim((string) config('app.url'), '/').'/status/'.$page->slug,
                    ], 403);
                }

                return redirect()->guest(route('login'));
            }
            abort_unless(self::workspaceOf($page)->members()->whereKey($user->id)->exists(), 404);
        }

        return $page;
    }

    /** @return Report */
    private static function report(StatusPage|WorkspaceStatusPage $page): array
    {
        return $page instanceof WorkspaceStatusPage
            ? (new FleetStatusReport($page))->toArray()
            : (new StatusReport($page))->toArray();
    }

    private static function link(StatusPage|WorkspaceStatusPage $page, string $file = ''): string
    {
        $request = request();
        $base = $request->attributes->get('status_base');
        if (is_string($base) && $request->attributes->get('status_page') === $page->slug) {
            return url($base.$file);
        }

        return route('status.show', $page->slug).$file;
    }

    /**
     * @param  array<string, mixed>  $report
     * @return array<string, string>
     */
    private static function meta(StatusPage|WorkspaceStatusPage $page, array $report): array
    {
        $uptime = $report['uptimePercent'] === null ? null : rtrim(rtrim(number_format((float) $report['uptimePercent'], 2), '0'), '.').'% uptime over the last 90 days.';
        $notice = $report['notice']['text'] ?? null;

        return [
            'title' => ($page->page_title ?: $report['title']).' · '.$report['overall']['label'],
            'description' => trim(implode(' ', array_filter([
                $report['overall']['label'].'.',
                $uptime,
                $notice !== null ? Str::limit($notice, 140) : null,
            ]))),
            'image' => self::link($page, '/og.png').'?v='.self::version($report),
            'image_alt' => $report['title'].': '.$report['overall']['label'],
            'color' => $colour = StatusOgImage::COLOURS[$report['overall']['status']] ?? StatusOgImage::COLOURS['unknown'],
            'discord' => StatusDiscordEmbed::encode(StatusDiscordEmbed::payload($report, self::link($page), $colour)),
        ];
    }

    /** @param  array<string, mixed>  $report */
    private static function version(array $report): string
    {
        $components = (string) json_encode(StatusOgImage::components($report));

        return substr(md5(implode('|', [$report['title'], $report['overall']['label'], $report['uptimePercent'], $components])), 0, 10);
    }

    public static function abortUnlessServedHere(Request $request, StatusPage|WorkspaceStatusPage $page): void
    {
        $workspace = $request->attributes->get('custom_workspace');
        abort_if(is_int($workspace) && $workspace !== 0 && self::workspaceOf($page)->id !== $workspace, 404);
    }

    private static function workspaceOf(StatusPage|WorkspaceStatusPage $page): Workspace
    {
        return $page instanceof WorkspaceStatusPage ? $page->workspace : $page->machine->workspace;
    }

    public static function badgeSvg(string $left, string $right, string $color): string
    {
        $width = fn (string $text) => (int) round(strlen($text) * 6.6 + 12);
        $lw = $width($left);
        $rw = $width($right);
        $total = $lw + $rw;
        $l = htmlspecialchars($left, ENT_XML1);
        $r = htmlspecialchars($right, ENT_XML1);
        $lx = $lw / 2;
        $rx = $lw + $rw / 2;

        return <<<SVG
<svg xmlns="http://www.w3.org/2000/svg" width="{$total}" height="20" role="img" aria-label="{$l}: {$r}">
  <title>{$l}: {$r}</title>
  <clipPath id="r"><rect width="{$total}" height="20" rx="4"/></clipPath>
  <g clip-path="url(#r)">
    <rect width="{$lw}" height="20" fill="#3b3b40"/>
    <rect x="{$lw}" width="{$rw}" height="20" fill="{$color}"/>
  </g>
  <g fill="#fff" text-anchor="middle" font-family="Verdana,DejaVu Sans,sans-serif" font-size="11">
    <text x="{$lx}" y="14">{$l}</text>
    <text x="{$rx}" y="14">{$r}</text>
  </g>
</svg>
SVG;
    }
}

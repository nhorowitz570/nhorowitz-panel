<?php

use App\Enums\Feature;
use App\Models\User;
use App\Models\Workspace;
use App\Support\Entitlements\Entitlements;
use App\Support\Extensions\Extensions;
use App\Support\Extensions\SettingsItem;
use App\Support\Licensing\CommunityLicense;
use Illuminate\Routing\RouteCollection;
use Illuminate\Support\Facades\Route;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    config()->set('serveros.coming_soon', false);
});

function communityLicenseInstall(string $edition, bool $packageLicensePage = false): void
{
    config(['serveros.edition' => $edition]);
    app()->forgetInstance(Entitlements::class);

    app()->instance(Extensions::class, $extensions = new Extensions);
    CommunityLicense::register($extensions);
    if ($packageLicensePage) {
        $extensions->addSettingsItem(new SettingsItem(
            key: 'license',
            label: 'License',
            icon: 'key',
            path: '/settings/license',
            group: 'workspace',
            component: 'workspace/settings/License',
        ));
        $extensions->routes(Extensions::WORKSPACE, function () {
            Route::get('settings/license', fn (Workspace $workspace) => 'the package’s page')->name('workspace.settings.license');
        });
    }

    $router = app('router');
    $router->setRoutes(new RouteCollection);
    Route::middleware('web')->group(base_path('routes/web.php'));
    Route::middleware('api')->prefix('api')->group(base_path('routes/api.php'));
    $router->getRoutes()->refreshNameLookups();
    $router->getRoutes()->refreshActionLookups();
    app('url')->setRoutes($router->getRoutes());
}

function communityLicenseUrl(Workspace $workspace, string $path = '/settings/license'): string
{
    return 'https://'.$workspace->host().$path;
}

/** @return list<string> */
function communityLicenseSettingsKeys(Workspace $workspace, User $user): array
{
    return array_column(app(Extensions::class)->settingsFor($workspace, $user), 'key');
}

test('a self-hosted install built from source has its own License page, saying how to get the paid features', function () {
    communityLicenseInstall('self-hosted');
    config(['serveros.install.docker' => true]);
    $workspace = Workspace::factory()->create();

    expect(communityLicenseSettingsKeys($workspace, $workspace->owner))->toBe(['community-license']);

    $this->actingAs($workspace->owner)
        ->get(communityLicenseUrl($workspace))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('workspace/settings/CommunityLicense')
            ->where('image', 'ghcr.io/serveroshq/panel:latest')
            ->where('docker', true)
            ->where('paidFeatures', ['custom_roles', 'sso', 'scim', 'custom_domains', 'own_log_storage', 'fleet_status_page']));

    $this->get(communityLicenseUrl($workspace, '/settings/members'))
        ->assertInertia(fn (Assert $page) => $page->where('extensions.settings.0.key', 'community-license')
            ->where('extensions.settings.0.path', '/settings/license'));

    foreach (CommunityLicense::paidFeatures() as $feature) {
        expect($workspace->hasFeature(Feature::from($feature)))->toBeFalse();
    }

    $member = User::factory()->create();
    $workspace->members()->attach($member, ['role' => 'member']);
    expect(communityLicenseSettingsKeys($workspace, $member))->toBe([]);
});

test('serveros.com never shows it', function () {
    communityLicenseInstall('cloud');
    $workspace = Workspace::factory()->create();

    expect(communityLicenseSettingsKeys($workspace, $workspace->owner))->toBe([])
        ->and(Route::has('workspace.settings.license'))->toBeFalse();

    $this->actingAs($workspace->owner)->get(communityLicenseUrl($workspace))->assertNotFound();
});

test('a package with its own License page takes its place', function () {
    communityLicenseInstall('self-hosted', packageLicensePage: true);
    $workspace = Workspace::factory()->create();

    expect(CommunityLicense::shown())->toBeFalse()
        ->and(communityLicenseSettingsKeys($workspace, $workspace->owner))->toBe(['license']);

    $this->actingAs($workspace->owner)
        ->get(communityLicenseUrl($workspace))
        ->assertOk()
        ->assertSee('the package’s page');
});

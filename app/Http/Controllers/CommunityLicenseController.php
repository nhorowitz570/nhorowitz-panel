<?php

namespace App\Http\Controllers;

use App\Models\Workspace;
use App\Support\Licensing\CommunityLicense;
use Inertia\Inertia;
use Inertia\Response;

class CommunityLicenseController extends Controller
{
    public function __invoke(Workspace $workspace): Response
    {
        abort_unless(CommunityLicense::shown(), 404);

        return Inertia::render('workspace/settings/CommunityLicense', [
            'workspace' => $workspace->toPageProps(),
            'paidFeatures' => CommunityLicense::paidFeatures(),
            'image' => CommunityLicense::OFFICIAL_IMAGE.':latest',
            'docker' => (bool) config('serveros.install.docker'),
        ]);
    }
}

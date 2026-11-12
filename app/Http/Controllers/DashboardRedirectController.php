<?php

namespace App\Http\Controllers;

use App\Actions\Workspaces\CreateWorkspace;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\Response;

class DashboardRedirectController extends Controller
{
    public function __invoke(Request $request, CreateWorkspace $createWorkspace): Response
    {
        $user = $request->user();

        $workspace = $user->defaultWorkspace() ?? $createWorkspace->create($user);

        $to = $request->query('to');
        $path = is_string($to) && preg_match('#^/(?!/)[\w\-./?=&%]*$#', $to) ? $to : '';

        return Inertia::location(rtrim($workspace->url(), '/').$path);
    }
}

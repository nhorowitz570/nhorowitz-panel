<?php

namespace App\Http\Controllers;

use App\Http\Middleware\HandleInertiaRequests;
use App\Models\Workspace;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PanelVersionController extends Controller
{
    public function __invoke(Request $request, Workspace $workspace, HandleInertiaRequests $inertia): JsonResponse
    {
        return response()->json(['version' => $inertia->version($request)])
            ->header('Cache-Control', 'no-store');
    }
}

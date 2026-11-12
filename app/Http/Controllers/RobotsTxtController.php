<?php

namespace App\Http\Controllers;

use Illuminate\Http\Response;

class RobotsTxtController extends Controller
{
    public function __invoke(): Response
    {
        return response("# A ServerOS panel: everything here is behind a sign-in, and status\n# pages are shared by their links, so there's nothing to index.\nUser-agent: *\nDisallow: /\n", 200, [
            'Content-Type' => 'text/plain; charset=UTF-8',
            'Cache-Control' => 'public, max-age=3600',
        ]);
    }
}

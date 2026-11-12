<?php

namespace App\Support\Mcp;

use Inertia\Inertia;
use Symfony\Component\HttpFoundation\Response;

final class OAuthPage
{
    public static function problem(string $message): Response
    {
        return self::frameless(Inertia::render('auth/OAuthAuthorize', ['problem' => $message])->toResponse(request()));
    }

    public static function frameless(Response $response): Response
    {
        $response->headers->set('X-Frame-Options', 'DENY');
        $response->headers->set('Content-Security-Policy', "frame-ancestors 'none'");
        $response->headers->set('Referrer-Policy', 'no-referrer');

        return $response;
    }
}

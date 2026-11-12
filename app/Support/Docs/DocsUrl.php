<?php

namespace App\Support\Docs;

use Illuminate\Support\Facades\Route;

final class DocsUrl
{
    public const string PUBLIC = 'https://docs.serveros.com';

    public static function home(): string
    {
        return Route::has('docs.index') ? rtrim(route('docs.index'), '/') : self::PUBLIC;
    }

    public static function page(string $section, string $page): string
    {
        return Route::has('docs.show')
            ? route('docs.show', [$section, $page])
            : self::PUBLIC.'/'.rawurlencode($section).'/'.rawurlencode($page);
    }
}

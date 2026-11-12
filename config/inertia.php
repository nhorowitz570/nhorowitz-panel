<?php

return [

    'ssr' => [
        'enabled' => (bool) env('INERTIA_SSR_ENABLED', true),
        'url' => env('INERTIA_SSR_URL', 'http://127.0.0.1:13714'),
        'bundle' => base_path('bootstrap/ssr/ssr.js'),
        'ensure_bundle_exists' => true,
        'timeout' => (float) env('INERTIA_SSR_TIMEOUT', 1.5),

    ],

    'pages' => [

        'paths' => [
            resource_path('js/pages'),
            ...glob(base_path('vendor/serveroshq/*/resources/js/pages'), GLOB_ONLYDIR) ?: [],
            ...glob(base_path('vendor/serveroshq/*/resources/js/marketing/pages'), GLOB_ONLYDIR) ?: [],
        ],

        'extensions' => [
            'js',
            'jsx',
            'svelte',
            'ts',
            'tsx',
            'vue',
        ],

    ],

    'testing' => [

        'ensure_pages_exist' => true,

    ],

];

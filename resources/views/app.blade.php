<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" @class(['dark' => ($appearance ?? 'system') == 'dark'])>
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <meta name="csrf-token" content="{{ csrf_token() }}">

        <script>
            (function() {
                const appearance = '{{ $appearance ?? "system" }}';

                if (appearance === 'system') {
                    const prefersDark = window.matchMedia('(prefers-color-scheme: dark)').matches;

                    if (prefersDark) {
                        document.documentElement.classList.add('dark');
                    }
                }
            })();
        </script>

        @include('partials.gpu-check')

        <style>
            html {
                background-color: oklch(1 0 0);
            }

            html.dark {
                background-color: #111111;
            }
        </style>
        @if ($page['component'] === 'status/Embed')
            @php($embedTheme = $page['props']['theme'] ?? 'light')
            <style>
                html, html.dark, body { background: #ffffff !important; color-scheme: light; }
                @if ($embedTheme === 'dark')
                    html, html.dark, body { background: #161618 !important; color-scheme: dark; }
                @elseif ($embedTheme === 'auto')
                    @media (prefers-color-scheme: dark) { html, html.dark, body { background: #161618 !important; color-scheme: dark; } }
                @endif
            </style>
        @endif

        @if ($page['component'] === 'status/Show' && ! empty($page['props']['meta']))
            @php($meta = $page['props']['meta'])
            <meta name="description" content="{{ $meta['description'] }}">
            <link rel="canonical" href="{{ url()->current() }}">
            <meta property="og:type" content="website">
            <meta property="og:site_name" content="ServerOS">
            <meta name="theme-color" content="{{ $meta['color'] }}">
            <meta property="og:url" content="{{ url()->current() }}">
            <meta property="og:title" content="{{ $meta['title'] }}">
            <meta property="og:description" content="{{ $meta['description'] }}">
            <meta property="og:image" content="{{ $meta['image'] }}">
            <meta property="og:image:width" content="1200">
            <meta property="og:image:height" content="630">
            <meta property="og:image:alt" content="{{ $meta['image_alt'] }}">
            <meta name="twitter:card" content="summary_large_image">
            <meta name="twitter:title" content="{{ $meta['title'] }}">
            <meta name="twitter:description" content="{{ $meta['description'] }}">
            <meta name="twitter:image" content="{{ $meta['image'] }}">
            @if (! empty($meta['discord']))
                {{-- Raw output is safe only because it's encoded with < > & escaped. --}}
                <script id="discord:component-embed" type="application/json">{!! $meta['discord'] !!}</script>
            @endif
        @endif

        @if ($page['component'] === 'status/Show' && ! empty($page['props']['favicon']))
            <link rel="icon" href="{{ $page['props']['favicon'] }}">
            <link rel="apple-touch-icon" href="{{ $page['props']['favicon'] }}">
        @else
            @include('partials.favicons')
        @endif

        @fonts(['instrument-sans', 'mona-sans', 'geist-mono', 'cabin', 'inter', 'schibsted-grotesk'])

        @if (($headTags = app(\App\Support\Extensions\Extensions::class)->headTags($page)) !== [])
            @foreach ($headTags as $headTag)
                {{ $headTag }}
            @endforeach
        @endif

        @vite(['resources/css/app.css', 'resources/js/app.ts', \App\Support\Extensions\Extensions::pageEntry($page['component'], 'pages', 'vue')])
        @php($panelLines = app()->getLocale() !== 'en' && ! Vite::isRunningHot() && is_file(lang_path(app()->getLocale().'.json')) ? rescue(fn () => Vite::asset('lang/'.app()->getLocale().'.json'), null, false) : null)
        @if ($panelLines)
            <link rel="modulepreload" href="{{ $panelLines }}">
        @endif
        @php($indexable = $page['component'] === 'status/Show')
        @php($entry = in_array($page['component'], ['auth/Login', 'auth/Register'], true)
            && request()->getHost() === parse_url((string) config('app.url'), PHP_URL_HOST))
        @if ($entry)
            <link rel="canonical" href="{{ url()->current() }}">
        @elseif (! $indexable)
            <meta name="robots" content="noindex">
        @endif
        @php($authTitle = ['auth/Login' => 'Sign in', 'auth/Register' => 'Create account', 'auth/ForgotPassword' => 'Forgot password', 'auth/ResetPassword' => 'Reset password'][$page['component']] ?? null)
        @if ($authTitle)
            <meta name="description" content="{{ $page['component'] === 'auth/Register' ? 'Create a ServerOS account: connect the servers you already own and manage everything on them from one panel.' : 'Sign in to ServerOS, the panel for the servers you already own.' }}">
        @endif
        <x-inertia::head>
            <title>{{ $indexable && ! empty($page['props']['meta']['title']) ? $page['props']['meta']['title'] : ($authTitle ? $authTitle.' - '.config('app.name') : config('app.name', 'Laravel')) }}</title>
        </x-inertia::head>
    </head>
    <body class="font-sans antialiased">
        <!--email_off-->
        @if ($indexable && ! empty($page['props']['meta']['title']))
            <header id="page-outline" class="sr-only">
                <h1>{{ $page['props']['meta']['title'] }}</h1>
                <p>{{ $page['props']['meta']['description'] ?? '' }}</p>
            </header>
        @endif
        <x-inertia::app />
        <!--/email_off-->
    </body>
</html>

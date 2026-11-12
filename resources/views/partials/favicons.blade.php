@if (app()->environment('local'))
    <link rel="icon" href="/favicon-local.png" sizes="64x64" type="image/png">
    <link rel="icon" href="/favicon-local.svg" type="image/svg+xml">
    <link rel="apple-touch-icon" href="/apple-touch-icon-local.png">
@else
    <link rel="icon" href="/favicon.ico?v=6" sizes="32x32">
    <link rel="icon" href="/favicon.png?v=6" sizes="64x64" type="image/png">
    <link rel="apple-touch-icon" href="/apple-touch-icon.png?v=6">
@endif

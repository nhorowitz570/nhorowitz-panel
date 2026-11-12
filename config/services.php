<?php

return [

    'postmark' => [
        'key' => env('POSTMARK_API_KEY'),
    ],

    'resend' => [
        'key' => env('RESEND_API_KEY'),

        'webhook_secret' => env('RESEND_WEBHOOK_SECRET'),
    ],

    'discord' => [
        'changelog_webhook' => env('DISCORD_CHANGELOG_WEBHOOK'),
    ],

    'groq' => [
        'key' => env('GROQ_API_KEY'),
        'model' => env('GROQ_MODEL') ?: 'openai/gpt-oss-120b',
        'agent_queue' => env('AGENT_QUEUE') ?: 'default',

        'translate_key' => env('GROQ_TRANSLATE_KEY') ?: env('GROQ_API_KEY'),
        'translate_model' => env('GROQ_TRANSLATE_MODEL') ?: 'openai/gpt-oss-120b',
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

    'github' => [
        'token' => env('GITHUB_TOKEN'),
        'client_id' => env('GITHUB_CLIENT_ID'),
        'client_secret' => env('GITHUB_CLIENT_SECRET'),
        'redirect' => '/auth/github/callback',
    ],

    'turnstile' => [
        'site_key' => env('TURNSTILE_SITE_KEY'),
        'secret_key' => env('TURNSTILE_SECRET_KEY'),
    ],

    'cloudflare' => [
        'token' => env('CLOUDFLARE_API_TOKEN'),
        'zone_id' => env('CLOUDFLARE_ZONE_ID'),
        'cname_target' => env('CLOUDFLARE_CNAME_TARGET', 'customers.'.env('APP_DOMAIN', parse_url((string) env('APP_URL', 'http://localhost'), PHP_URL_HOST))),
    ],

    'github_app' => [
        'id' => env('GITHUB_APP_ID'),
        'slug' => env('GITHUB_APP_SLUG'),
        'client_id' => env('GITHUB_APP_CLIENT_ID') ?: env('GITHUB_CLIENT_ID'),
        'client_secret' => env('GITHUB_APP_CLIENT_SECRET') ?: env('GITHUB_CLIENT_SECRET'),
        'private_key' => env('GITHUB_APP_PRIVATE_KEY'),

        'webhook_secret' => env('GITHUB_APP_WEBHOOK_SECRET'),
    ],

    'google' => [
        'client_id' => env('GOOGLE_CLIENT_ID'),
        'client_secret' => env('GOOGLE_CLIENT_SECRET'),
        'redirect' => '/auth/google/callback',
        'identity_button' => (bool) env('GOOGLE_IDENTITY_BUTTON', false),
    ],

    'ntfy' => [
        'server' => env('NTFY_SERVER', 'https://ntfy.sh'),
        'topic' => env('NTFY_TOPIC'),
        'token' => env('NTFY_TOKEN'),
    ],

    'seline' => [
        'token' => env('SELINE_TOKEN'),
    ],

];

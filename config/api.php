<?php

return [
    'rate_limits' => [
        'starter' => (int) env('API_RATE_STARTER', 60),
        'pro' => (int) env('API_RATE_PRO', 300),
        'business' => (int) env('API_RATE_BUSINESS', 1000),
    ],
];

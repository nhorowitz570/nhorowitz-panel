<?php

return [
    'allow_private' => (bool) env('MONITORS_ALLOW_PRIVATE', false),

    'check_certificates' => (bool) env('MONITORS_CHECK_CERTIFICATES', true),
];

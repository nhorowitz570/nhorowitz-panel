<?php

namespace App\Support\Mcp;

final readonly class OAuthConsent
{
    /**
     * @param  array<string, mixed>  $pending
     * @param  array<string, mixed>  $props
     */
    public function __construct(
        public array $pending,
        public array $props,
    ) {}
}

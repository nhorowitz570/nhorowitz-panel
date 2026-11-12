<?php

namespace App\Support\Mcp;

use App\Models\Workspace;

final readonly class OAuthGrant
{
    /**
     * @param  list<string>  $scopes
     */
    public function __construct(
        public array $scopes,
        public string $resource,
        public ?Workspace $workspace = null,
    ) {}
}

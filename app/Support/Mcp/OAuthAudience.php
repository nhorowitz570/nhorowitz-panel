<?php

namespace App\Support\Mcp;

use App\Models\McpOAuthClient;
use App\Models\User;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

abstract class OAuthAudience
{
    // Stored with codes and tokens: changing it breaks every existing token.
    abstract public function key(): string;

    /** @return list<string> */
    abstract public function scopes(): array;

    /** @return array<string, array{label: string, description: string}> */
    abstract public function describe(): array;

    /**
     * @param  string|list<string>|null  $requested
     * @return list<string>
     */
    abstract public function normalise(string|array|null $requested): array;

    abstract public function claims(mixed $resource): bool;

    abstract public function consent(Request $request, User $user, mixed $resource): OAuthConsent|Response;

    /**
     * @param  array<string, mixed>  $pending
     * @param  array<string, mixed>  $choices
     */
    abstract public function approve(User $user, array $pending, array $choices): OAuthGrant|Response;

    public function approved(User $user, McpOAuthClient $client, OAuthGrant $grant): void {}
}

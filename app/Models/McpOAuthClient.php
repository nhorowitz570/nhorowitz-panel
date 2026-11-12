<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property int $id
 * @property string $client_id
 * @property string $name
 * @property list<string> $redirect_uris
 * @property string|null $client_uri
 * @property string|null $software_id
 * @property string|null $created_ip
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
class McpOAuthClient extends Model
{
    protected $table = 'mcp_oauth_clients';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['redirect_uris' => 'array'];
    }

    /** @return HasMany<McpToken, $this> */
    public function tokens(): HasMany
    {
        return $this->hasMany(McpToken::class, 'mcp_oauth_client_id');
    }

    /** @return HasMany<McpOAuthCode, $this> */
    public function codes(): HasMany
    {
        return $this->hasMany(McpOAuthCode::class, 'mcp_oauth_client_id');
    }

    public function allowsRedirect(string $uri): bool
    {
        return in_array($uri, $this->redirect_uris, true);
    }
}

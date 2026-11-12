<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * The GitHub App a self-hosted install made for itself, saved from Settings ›
 * General. One row at most; GITHUB_APP_* settings in .env win over it.
 *
 * @property int $id
 * @property string $app_id
 * @property string $slug
 * @property string|null $name
 * @property string|null $owner_login
 * @property string|null $html_url
 * @property string $client_id
 * @property string $client_secret
 * @property string $private_key
 * @property string|null $webhook_secret
 * @property int|null $created_by
 * @property Carbon $created_at
 * @property Carbon $updated_at
 */
class GitHubAppSetting extends Model
{
    protected $table = 'github_app_settings';

    protected $guarded = ['id'];

    protected $hidden = ['client_secret', 'private_key', 'webhook_secret'];

    protected function casts(): array
    {
        return ['client_secret' => 'encrypted', 'private_key' => 'encrypted', 'webhook_secret' => 'encrypted'];
    }

    /** @return BelongsTo<User, $this> */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}

<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * @property int $id
 * @property string $subscribable_type
 * @property int $subscribable_id
 * @property string $email
 * @property string $token
 * @property CarbonImmutable|null $confirmed_at
 * @property CarbonImmutable|null $confirm_sent_at
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 * @property-read StatusPage|WorkspaceStatusPage $subscribable
 */
class StatusSubscriber extends Model
{
    protected $guarded = ['id'];

    protected $hidden = ['token'];

    protected function casts(): array
    {
        return [
            'confirmed_at' => 'immutable_datetime',
            'confirm_sent_at' => 'immutable_datetime',
        ];
    }

    /** @return MorphTo<Model, $this> */
    public function subscribable(): MorphTo
    {
        return $this->morphTo();
    }

    public function confirmUrl(): string
    {
        return route('status.subscribe.confirm', ['slug' => $this->subscribable->slug, 'token' => $this->token]);
    }

    public function unsubscribeUrl(): string
    {
        return route('status.unsubscribe', ['slug' => $this->subscribable->slug, 'token' => $this->token]);
    }
}

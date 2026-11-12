<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 * @property int $monitor_id
 * @property CarbonImmutable $checked_at
 * @property bool $ok
 * @property int|null $response_ms
 * @property int|null $status_code
 * @property string|null $error
 */
class MonitorCheck extends Model
{
    public const KEEP_DAYS = 7;

    public $timestamps = false;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'checked_at' => 'datetime',
            'ok' => 'boolean',
        ];
    }
}

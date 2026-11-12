<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $machine_id
 * @property string $name
 * @property string|null $arch
 * @property string|null $installed
 * @property string $candidate
 * @property bool $security
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 * @property-read Machine $machine
 */
class MachinePackageUpdate extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['security' => 'boolean'];
    }

    /** @return BelongsTo<Machine, $this> */
    public function machine(): BelongsTo
    {
        return $this->belongsTo(Machine::class);
    }
}

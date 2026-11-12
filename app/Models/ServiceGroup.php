<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * @property string|null $icon_path
 * @property int $id
 * @property int $machine_id
 * @property string $name
 * @property list<string> $services
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 * @property-read Machine $machine
 */
#[Fillable(['name', 'services'])]
class ServiceGroup extends Model
{
    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['services' => 'array'];
    }

    /** @return BelongsTo<Machine, $this> */
    public function machine(): BelongsTo
    {
        return $this->belongsTo(Machine::class);
    }

    /** @param  list<string>  $services */
    public static function put(Machine $machine, self $group, string $name, array $services): self
    {
        $services = array_values(array_unique($services));

        DB::transaction(function () use ($machine, $group, $name, $services) {
            $group->forceFill(['machine_id' => $machine->id, 'name' => $name, 'services' => $services])->save();

            foreach ($machine->serviceGroups()->whereKeyNot($group->id)->get() as $other) {
                $remaining = array_values(array_diff($other->services, $services));
                if ($remaining !== $other->services) {
                    $other->forceFill(['services' => $remaining])->save();
                }
            }
        });

        return $group;
    }

    /**
     * @return array{id: int, name: string, services: list<string>, iconUrl: string|null}
     */
    public function toPageProps(): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'services' => $this->services,
            'iconUrl' => $this->icon_path === null ? null : Storage::disk('public')->url($this->icon_path),
        ];
    }
}

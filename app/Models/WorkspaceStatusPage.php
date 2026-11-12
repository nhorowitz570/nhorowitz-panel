<?php

namespace App\Models;

use App\Models\Concerns\HasStatusBranding;
use App\Support\Domains\CustomDomains;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphMany;

/**
 * @property int $id
 * @property int $workspace_id
 * @property string $slug
 * @property bool $enabled
 * @property bool $public
 * @property string|null $title
 * @property string|null $description
 * @property string|null $page_title
 * @property string|null $icon_path
 * @property string|null $favicon_path
 * @property string|null $notice
 * @property string|null $notice_kind
 * @property bool $show_uptime
 * @property list<int>|null $machines
 * @property list<int>|null $monitors
 * @property list<int>|null $machine_groups
 * @property array{sections?: list<array{name: string, components: list<string>}>, headings?: array<int|string, string>, order?: list<int>}|null $layout
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 * @property-read Workspace $workspace
 */
class WorkspaceStatusPage extends Model
{
    use HasStatusBranding;

    protected $guarded = ['id', 'workspace_id'];

    protected static function booted(): void
    {
        static::updated(function (WorkspaceStatusPage $page) {
            if ($page->wasChanged('slug')) {
                CustomDomains::forget();
            }
        });
    }

    protected function casts(): array
    {
        return [
            'enabled' => 'boolean',
            'public' => 'boolean',
            'show_uptime' => 'boolean',
            'machines' => 'array',
            'monitors' => 'array',
            'machine_groups' => 'array',
            'layout' => 'array',
        ];
    }

    /** @return MorphMany<StatusSubscriber, $this> */
    public function subscribers(): MorphMany
    {
        return $this->morphMany(StatusSubscriber::class, 'subscribable');
    }

    /** @return BelongsTo<Workspace, $this> */
    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }

    public function live(): bool
    {
        return $this->enabled && $this->workspace->hasFleetStatusPage();
    }

    public function covers(Machine $machine): bool
    {
        return $machine->workspace_id === $this->workspace_id
            && ($this->machines === null
                || in_array($machine->id, $this->machines, true)
                || ($machine->machine_group_id !== null && in_array($machine->machine_group_id, $this->machine_groups ?? [], true)));
    }

    /** @return Collection<int, Monitor> */
    public function standaloneMonitors(): Collection
    {
        if ($this->monitors === null || $this->monitors === []) {
            return new Collection;
        }

        return Monitor::query()
            ->where('workspace_id', $this->workspace_id)
            ->whereNull('machine_id')
            ->where('paused', false)
            ->whereIn('id', $this->monitors)
            ->orderBy('name')
            ->get();
    }

    /** @return Collection<int, Machine> */
    public function coveredMachines(): Collection
    {
        return $this->workspace->machines()
            ->whereNotNull('uid')
            ->when($this->machines !== null, fn ($q) => $q->where(fn ($q) => $q
                ->whereIn('id', $this->machines)
                ->orWhereIn('machine_group_id', $this->machine_groups ?? [])))
            ->with(['statusPage', 'serviceGroups', 'workspace'])
            ->orderBy('name')
            ->get();
    }
}

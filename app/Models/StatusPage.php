<?php

namespace App\Models;

use App\Models\Concerns\HasStatusBranding;
use App\Support\Domains\CustomDomains;
use App\Support\Machines\MachinePolicies;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Support\Str;

/**
 * @property int $id
 * @property int $machine_id
 * @property string $slug
 * @property bool $enabled
 * @property bool $public
 * @property string|null $title
 * @property string|null $description
 * @property string|null $page_title
 * @property string|null $icon_path
 * @property string|null $favicon_path
 * @property array<string, array{shown?: bool, label?: string|null, quiet?: bool, section?: string}>|null $services
 * @property bool $include_new
 * @property bool $show_uptime
 * @property string|null $notice
 * @property string|null $notice_kind
 * @property array<string, array{batches: int, services: array<string, array{0: int, 1: int}>, last?: list<string>, grace?: list<string>}>|null $uptime
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 * @property-read Machine $machine
 */
class StatusPage extends Model
{
    use HasStatusBranding;

    public const NOTICE_KINDS = ['info', 'maintenance', 'incident'];

    public const UPTIME_DAYS = 90;

    protected $guarded = ['id', 'machine_id', 'uptime'];

    protected static function booted(): void
    {
        static::updated(function (StatusPage $page) {
            if ($page->wasChanged('slug')) {
                WorkspaceDomain::query()
                    ->where('workspace_id', Machine::query()->whereKey($page->machine_id)->value('workspace_id'))
                    ->where('status_page_slug', $page->getOriginal('slug'))
                    ->update(['status_page_slug' => $page->slug]);
                CustomDomains::forget();
            }
        });
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'enabled' => 'boolean',
            'public' => 'boolean',
            'include_new' => 'boolean',
            'show_uptime' => 'boolean',
            'services' => 'array',
            'uptime' => 'array',
        ];
    }

    /** @return MorphMany<StatusSubscriber, $this> */
    public function subscribers(): MorphMany
    {
        return $this->morphMany(StatusSubscriber::class, 'subscribable');
    }

    /** @return BelongsTo<Machine, $this> */
    public function machine(): BelongsTo
    {
        return $this->belongsTo(Machine::class);
    }

    public static function draftFor(Machine $machine): self
    {
        $base = Str::slug($machine->name) ?: 'status';
        $slug = $base;
        while (self::query()->where('slug', $slug)->exists() || WorkspaceStatusPage::query()->where('slug', $slug)->exists()) {
            $slug = $base.'-'.Str::lower(Str::random(4));
        }

        $includeNew = MachinePolicies::for($machine)->status_page_new;
        $page = new self(['slug' => $slug, 'enabled' => false, 'public' => true, 'include_new' => $includeNew, 'show_uptime' => true]);
        $page->machine_id = $machine->id;
        $page->setRelation('machine', $machine);

        return $page;
    }

    public static function recordingFor(Machine $machine): ?self
    {
        $page = $machine->statusPage;
        if ($page?->live()) {
            return $page;
        }

        $fleet = $machine->workspace->fleetStatusPage;
        if ($fleet === null || ! $fleet->live() || ! $fleet->covers($machine)) {
            return null;
        }

        if ($page === null) {
            $page = self::draftFor($machine);
            $page->save();
            $machine->setRelation('statusPage', $page);
        }

        return $page;
    }

    public function live(): bool
    {
        return $this->enabled && $this->machine->workspace->hasStatusPages();
    }

    /** @param  list<string>  $running */
    public function recordSnapshot(array $running, ?CarbonInterface $at = null): void
    {
        $day = ($at ?? now())->toDateString();
        $uptime = $this->uptime ?? [];
        $today = $uptime[$day] ?? ['batches' => 0, 'services' => []];
        $before = $today['last'] ?? (end($uptime) !== false && key($uptime) < $day ? (end($uptime)['last'] ?? []) : []);
        $credited = $today['grace'] ?? [];
        $today['batches']++;

        foreach (array_diff($credited, $running) as $key) {
            if (isset($today['services'][$key]) && $today['services'][$key][0] > 0) {
                $today['services'][$key][0]--;
            }
        }

        $grace = array_values(array_diff($before, $running, $credited));
        foreach ([...$running, ...$grace] as $key) {
            [$seen, $first] = $today['services'][$key] ?? [0, $today['batches']];
            $today['services'][$key] = [$seen + 1, $first];
        }

        $today['last'] = $running;
        $today['grace'] = $grace;
        $uptime[$day] = $today;
        ksort($uptime);

        $this->uptime = array_slice($uptime, -self::UPTIME_DAYS, null, true);
        $this->saveQuietly();
    }
}

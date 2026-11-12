<?php

namespace App\Models;

use App\Support\Machines\MachineMetadata;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property int $id
 * @property int $workspace_id
 * @property string $name
 * @property bool $is_default
 * @property bool $discovery
 * @property bool $logs
 * @property string $log_ip_masking
 * @property bool $security_watch
 * @property bool $error_reports
 * @property bool $status_page_new
 * @property string $updates
 * @property string|null $pinned_version
 * @property list<array{field: string, value: string}>|null $applies_to
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 * @property-read Workspace $workspace
 */
class MachinePolicy extends Model
{
    /** @var list<string> */
    public const MASKING = ['off', 'partial', 'hash'];

    /** @var list<string> */
    public const UPDATES = ['automatic', 'approve', 'pinned'];

    public const SETTINGS = ['discovery', 'logs', 'log_ip_masking', 'security_watch', 'error_reports', 'status_page_new', 'updates'];

    /** @var array<string, array<string, bool|string|null>> */
    public const PRESETS = [
        'own' => ['discovery' => true, 'logs' => true, 'log_ip_masking' => 'off', 'security_watch' => true, 'error_reports' => true, 'status_page_new' => false, 'updates' => 'automatic', 'pinned_version' => null],
        'agency' => ['discovery' => true, 'logs' => true, 'log_ip_masking' => 'off', 'security_watch' => true, 'error_reports' => true, 'status_page_new' => false, 'updates' => 'automatic', 'pinned_version' => null],
        'hosting' => ['discovery' => true, 'logs' => false, 'log_ip_masking' => 'partial', 'security_watch' => true, 'error_reports' => true, 'status_page_new' => false, 'updates' => 'approve', 'pinned_version' => null],
        'exploring' => ['discovery' => true, 'logs' => true, 'log_ip_masking' => 'off', 'security_watch' => true, 'error_reports' => true, 'status_page_new' => false, 'updates' => 'automatic', 'pinned_version' => null],
    ];

    protected $guarded = ['id', 'workspace_id'];

    protected $attributes = [
        'is_default' => false,
        'discovery' => true,
        'logs' => true,
        'log_ip_masking' => 'off',
        'security_watch' => true,
        'error_reports' => true,
        'status_page_new' => false,
        'updates' => 'automatic',
    ];

    protected function casts(): array
    {
        return [
            'is_default' => 'boolean',
            'discovery' => 'boolean',
            'logs' => 'boolean',
            'security_watch' => 'boolean',
            'error_reports' => 'boolean',
            'status_page_new' => 'boolean',
            'applies_to' => 'array',
        ];
    }

    /** @return BelongsTo<Workspace, $this> */
    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }

    /** @return HasMany<Machine, $this> */
    public function machines(): HasMany
    {
        return $this->hasMany(Machine::class);
    }

    /** @return HasMany<MachineGroup, $this> */
    public function groups(): HasMany
    {
        return $this->hasMany(MachineGroup::class);
    }

    /** @return array<string, bool|string|null> */
    public static function preset(?string $segment): array
    {
        return self::PRESETS[$segment] ?? self::PRESETS['own'];
    }

    /** @return array{discovery: bool, logs: bool, log_ip_masking: string, security_watch: bool, error_reports: bool, status_page_new: bool, updates: string, pinned_version: string|null} */
    public function settings(): array
    {
        return [
            'discovery' => $this->discovery,
            'logs' => $this->logs,
            'log_ip_masking' => $this->log_ip_masking,
            'security_watch' => $this->security_watch,
            'error_reports' => $this->error_reports,
            'status_page_new' => $this->status_page_new,
            'updates' => $this->updates,
            'pinned_version' => $this->updates === 'pinned' ? $this->pinned_version : null,
        ];
    }

    /** @return array{discovery: bool, logs: bool, log_ip_masking: string, security_watch: bool, error_reports: bool, updates_automatic: bool, pinned_version: string|null} */
    public function toDaemon(): array
    {
        return [
            'discovery' => $this->discovery,
            'logs' => $this->logs,
            'log_ip_masking' => $this->log_ip_masking,
            'security_watch' => $this->security_watch,
            'error_reports' => $this->error_reports,
            'updates_automatic' => $this->updates === 'automatic',
            'pinned_version' => $this->updates === 'pinned' ? $this->pinned_version : null,
        ];
    }

    public function appliesTo(Machine $machine): bool
    {
        foreach ($this->applies_to ?? [] as $rule) {
            if (MachineMetadata::matches($machine, $rule['field'], $rule['value'])) {
                return true;
            }
        }

        return false;
    }

    /** @return array<string, mixed> */
    public function toPageProps(): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'isDefault' => $this->is_default,
            ...$this->settings(),
            'appliesTo' => $this->applies_to ?? [],
        ];
    }
}

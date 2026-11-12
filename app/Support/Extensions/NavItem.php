<?php

namespace App\Support\Extensions;

use App\Models\User;
use App\Models\Workspace;
use Closure;

final readonly class NavItem
{
    /**
     * @param  list<string>  $components
     * @param  (Closure(Workspace, User|null): bool)|null  $visible
     */
    public function __construct(
        public string $key,
        public string $label,
        public string $icon,
        public string $path,
        public string $group,
        public array $components = [],
        public ?string $after = null,
        public ?Closure $visible = null,
        public ?string $before = null,
    ) {}

    public function visibleTo(Workspace $workspace, ?User $user): bool
    {
        return $this->visible === null || ($this->visible)($workspace, $user) === true;
    }

    /**
     * @return array{key: string, label: string, icon: string, path: string, group: string, components: list<string>, after: string|null, before: string|null}
     */
    public function toArray(): array
    {
        return [
            'key' => $this->key,
            'label' => __($this->label),
            'icon' => $this->icon,
            'path' => $this->path,
            'group' => $this->group,
            'components' => $this->components,
            'after' => $this->after,
            'before' => $this->before,
        ];
    }
}

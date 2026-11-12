<?php

namespace App\Support\Extensions;

use App\Models\User;
use App\Models\Workspace;
use Closure;

final readonly class SettingsItem
{
    /**
     * @param  list<array{label: string, anchor: string, keywords?: string}>  $sections
     * @param  (Closure(Workspace, User|null): bool)|null  $visible
     */
    public function __construct(
        public string $key,
        public string $label,
        public string $icon,
        public string $path,
        public string $group,
        public string $component,
        public string $keywords = '',
        public array $sections = [],
        public ?Closure $visible = null,
    ) {}

    public function visibleTo(Workspace $workspace, ?User $user): bool
    {
        return $this->visible === null || ($this->visible)($workspace, $user) === true;
    }

    /**
     * @return array{key: string, label: string, icon: string, path: string, group: string, component: string, keywords: string, sections: list<array{label: string, anchor: string, keywords: string}>}
     */
    public function toArray(): array
    {
        return [
            'key' => $this->key,
            'label' => __($this->label),
            'icon' => $this->icon,
            'path' => $this->path,
            'group' => $this->group,
            'component' => $this->component,
            'keywords' => $this->keywords,
            'sections' => array_map(fn (array $section) => [
                'label' => __($section['label']),
                'anchor' => $section['anchor'],
                'keywords' => $section['keywords'] ?? '',
            ], $this->sections),
        ];
    }
}

<?php

namespace App\Support\Extensions;

use App\Models\Workspace;

final readonly class UpgradeLink
{
    public function __construct(
        public string $label,
        public string $route,
    ) {}

    public function path(Workspace $workspace): string
    {
        return route($this->route, ['workspace' => $workspace->slug], false);
    }

    public function url(Workspace $workspace): string
    {
        return route($this->route, ['workspace' => $workspace->slug]);
    }

    /**
     * @return array{href: string, label: string}
     */
    public function toArray(Workspace $workspace): array
    {
        return [
            'href' => $this->path($workspace),
            'label' => __($this->label),
        ];
    }
}

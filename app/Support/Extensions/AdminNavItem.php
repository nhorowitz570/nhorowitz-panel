<?php

namespace App\Support\Extensions;

use App\Models\User;
use Closure;
use InvalidArgumentException;

final readonly class AdminNavItem
{
    public const array GROUPS = ['home', 'customers', 'growth', 'content', 'platform', 'tools'];

    /**
     * @param  list<string>  $components
     * @param  (Closure(User): bool)|null  $visible
     */
    public function __construct(
        public string $key,
        public string $label,
        public string $icon,
        public string $path,
        public string $group,
        public array $components = [],
        public ?string $after = null,
        public ?string $before = null,
        public ?Closure $visible = null,
        public ?string $badge = null,
        public bool $external = false,
    ) {
        if (! in_array($group, self::GROUPS, true)) {
            throw new InvalidArgumentException("There's no admin sidebar group called {$group}.");
        }
    }

    public function visibleTo(?User $user): bool
    {
        if ($user === null) {
            return false;
        }

        return $this->visible === null ? $user->can('viewAdmin') : ($this->visible)($user) === true;
    }

    /**
     * @param  list<self>  $items
     * @return list<self>
     */
    public static function arrange(array $items): array
    {
        $keys = array_map(fn (self $item) => $item->key, $items);
        $anchor = fn (self $item): ?array => match (true) {
            $item->before !== null && in_array($item->before, $keys, true) => ['before', $item->before],
            $item->after !== null && in_array($item->after, $keys, true) => ['after', $item->after],
            default => null,
        };

        $placed = array_values(array_filter($items, fn (self $item) => $anchor($item) === null));
        $waiting = array_values(array_filter($items, fn (self $item) => $anchor($item) !== null));

        do {
            $progress = false;
            foreach ($waiting as $i => $item) {
                [$side, $key] = $anchor($item);
                $at = array_search($key, array_map(fn (self $p) => $p->key, $placed), true);
                if ($at === false) {
                    continue;
                }
                array_splice($placed, $side === 'before' ? $at : $at + 1, 0, [$item]);
                unset($waiting[$i]);
                $progress = true;
            }
        } while ($progress && $waiting !== []);

        return [...$placed, ...array_values($waiting)];
    }

    /**
     * @return array{key: string, label: string, icon: string, href: string, components: list<string>, badge: string|null, external: bool}
     */
    public function toArray(): array
    {
        return [
            'key' => $this->key,
            'label' => $this->label,
            'icon' => $this->icon,
            'href' => $this->path,
            'components' => $this->components,
            'badge' => $this->badge,
            'external' => $this->external,
        ];
    }
}

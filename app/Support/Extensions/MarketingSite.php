<?php

namespace App\Support\Extensions;

final readonly class MarketingSite
{
    /**
     * @param  list<string>  $routes
     */
    public function __construct(
        public string $rootView,
        public array $routes,
    ) {}
}

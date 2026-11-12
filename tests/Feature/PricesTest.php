<?php

use App\Enums\BillingInterval;
use App\Enums\Currency;
use App\Enums\Plan;
use App\Models\Workspace;
use App\Support\Extensions\Extensions;
use App\Support\Extensions\NoPrices;
use App\Support\Extensions\Prices;
use App\Support\Mcp\McpUsage;
use Inertia\Testing\AssertableInertia as Assert;

function pricesWelcomeUrl(Workspace $workspace): string
{
    return 'https://'.$workspace->host().'/welcome';
}

test('without prices the panel shows plans and usage with no price', function () {
    app()->instance(Prices::class, new NoPrices);
    app()->instance(Extensions::class, new Extensions);
    $workspace = Workspace::factory()->welcoming()->create();

    expect(Plan::Pro->toPageProps())->toMatchArray(['monthlyPrice' => null, 'annualPrice' => null])
        ->and(McpUsage::summary($workspace)['packPrice'])->toBeNull();

    $this->actingAs($workspace->owner)->get(pricesWelcomeUrl($workspace))
        ->assertInertia(fn (Assert $page) => $page
            ->component('welcome/Welcome')
            ->where('plans.1.key', 'pro')
            ->where('plans.1.monthly', null)
            ->where('plans.1.annual', null)
            ->missing('pricing'));
});

test('prices come from whatever is bound to Prices', function () {
    app()->instance(Prices::class, new class implements Prices
    {
        public function plan(Plan $plan, BillingInterval $interval, Currency $currency): ?int
        {
            return $interval === BillingInterval::Annual ? 12300 : 1230;
        }

        public function mcpPack(Currency $currency): ?int
        {
            return 450;
        }
    });
    $workspace = Workspace::factory()->welcoming()->create();

    expect(Plan::Pro->toPageProps())->toMatchArray(['monthlyPrice' => 1230, 'annualPrice' => 12300])
        ->and(McpUsage::summary($workspace)['packPrice'])->toBe(450);

    $this->actingAs($workspace->owner)->get(pricesWelcomeUrl($workspace))
        ->assertInertia(fn (Assert $page) => $page
            ->where('plans.1.monthly', 1230)
            ->where('plans.1.annual', 12300));
});

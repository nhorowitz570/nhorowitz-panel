<?php

namespace Database\Factories;

use App\Enums\BillingInterval;
use App\Enums\Plan;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Workspace>
 */
class WorkspaceFactory extends Factory
{
    /** @return array<string, mixed> */
    public function definition(): array
    {
        $name = fake()->unique()->company();

        return [
            'owner_id' => User::factory(),
            'name' => $name,
            'slug' => Str::limit(Str::slug($name), 24, '').'-'.fake()->unique()->numberBetween(100, 999),
            'trial_ends_at' => now()->addDays(Workspace::TRIAL_DAYS),
            'onboarded_at' => now(),
        ];
    }

    public function onPlan(Plan $plan, BillingInterval $interval = BillingInterval::Monthly): static
    {
        return $this->state(fn () => [
            'plan' => $plan,
            'billing_interval' => $interval,
            'trial_ends_at' => null,
        ]);
    }

    public function welcoming(): static
    {
        return $this->state(fn () => ['onboarded_at' => null]);
    }

    public function trialExpired(): static
    {
        return $this->state(fn () => ['trial_ends_at' => now()->subDay()]);
    }

    public function configure(): static
    {
        return $this->afterCreating(function (Workspace $workspace) {
            $workspace->members()->syncWithoutDetaching([$workspace->owner_id => ['role' => 'owner']]);
        });
    }
}

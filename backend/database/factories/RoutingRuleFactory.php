<?php

namespace Database\Factories;

use App\Models\Agent;
use App\Models\Inbox;
use App\Models\Team;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\RoutingRule>
 */
class RoutingRuleFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'inbox_id' => Inbox::factory(),
            'name' => fake()->words(3, true),
            'priority' => 0,
            'conditions' => [],
            'team_id' => Team::factory(),
            'agent_id' => null,
            'is_active' => true,
        ];
    }

    public function toAgent(): static
    {
        return $this->state(fn (array $attributes) => [
            'team_id' => null,
            'agent_id' => Agent::factory(),
        ]);
    }
}

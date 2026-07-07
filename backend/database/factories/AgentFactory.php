<?php

namespace Database\Factories;

use App\Enums\AgentStatus;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\Agent>
 */
class AgentFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'display_name' => fake()->firstName(),
            'status' => AgentStatus::Offline,
            'max_concurrent_chats' => 5,
            'last_active_at' => null,
        ];
    }

    public function available(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => AgentStatus::Available,
            'last_active_at' => now(),
        ]);
    }
}

<?php

namespace Database\Factories;

use App\Enums\ConversationStatus;
use App\Models\Agent;
use App\Models\Inbox;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\Conversation>
 */
class ConversationFactory extends Factory
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
            'status' => ConversationStatus::Pending,
            'language' => 'ms',
            'visitor_name' => fake()->name(),
            'visitor_email' => fake()->safeEmail(),
            'visitor_phone' => fake()->phoneNumber(),
            'meta' => null,
            'last_activity_at' => now(),
        ];
    }

    public function english(): static
    {
        return $this->state(fn (array $attributes) => [
            'language' => 'en',
        ]);
    }

    public function awaitingAcceptance(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => ConversationStatus::AwaitingAcceptance,
        ]);
    }

    public function active(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => ConversationStatus::Active,
            'assigned_agent_id' => Agent::factory(),
            'accepted_at' => now(),
        ]);
    }

    public function closed(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => ConversationStatus::Closed,
            'assigned_agent_id' => Agent::factory(),
            'accepted_at' => now()->subMinutes(30),
            'closed_at' => now(),
        ]);
    }

    public function abandoned(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => ConversationStatus::Abandoned,
            'closed_at' => now(),
        ]);
    }
}

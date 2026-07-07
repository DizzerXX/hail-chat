<?php

namespace Database\Factories;

use App\Enums\SenderType;
use App\Models\Agent;
use App\Models\Conversation;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\Message>
 */
class MessageFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'conversation_id' => Conversation::factory(),
            'sender_type' => SenderType::Visitor,
            'agent_id' => null,
            'body' => fake()->sentence(),
            'meta' => null,
        ];
    }

    public function fromAgent(): static
    {
        return $this->state(fn (array $attributes) => [
            'sender_type' => SenderType::Agent,
            'agent_id' => Agent::factory(),
        ]);
    }

    public function fromBot(): static
    {
        return $this->state(fn (array $attributes) => [
            'sender_type' => SenderType::Bot,
        ]);
    }
}

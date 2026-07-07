<?php

namespace Database\Factories;

use App\Enums\DeliveryStatus;
use App\Enums\WebhookEvent;
use App\Models\Webhook;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\WebhookDelivery>
 */
class WebhookDeliveryFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'webhook_id' => Webhook::factory(),
            'event' => WebhookEvent::ConversationStarted->value,
            'payload' => ['conversation' => ['uuid' => fake()->uuid()]],
            'status' => DeliveryStatus::Pending,
            'attempts' => 0,
        ];
    }

    public function success(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => DeliveryStatus::Success,
            'attempts' => 1,
            'last_status_code' => 200,
            'completed_at' => now(),
        ]);
    }

    public function failed(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => DeliveryStatus::Failed,
            'attempts' => 3,
            'last_status_code' => 500,
            'last_error' => 'Internal Server Error',
            'completed_at' => now(),
        ]);
    }
}

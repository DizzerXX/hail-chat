<?php

namespace Database\Factories;

use App\Enums\WebhookEvent;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\Webhook>
 */
class WebhookFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->words(2, true),
            'url' => 'https://'.fake()->domainName().'/webhooks/tdechat',
            'secret' => Str::random(48),
            'events' => array_column(WebhookEvent::cases(), 'value'),
            'inbox_id' => null,
            'is_active' => true,
        ];
    }
}

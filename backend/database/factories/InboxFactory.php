<?php

namespace Database\Factories;

use App\Enums\ChannelType;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\Inbox>
 */
class InboxFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->company().' Support',
            'slug' => fake()->unique()->slug(3),
            'channel_type' => ChannelType::Standalone,
            'channel_config' => [],
            'bot_enabled' => true,
            'is_active' => true,
        ];
    }

    public function mitelOpenMedia(): static
    {
        return $this->state(fn (array $attributes) => [
            'channel_type' => ChannelType::MitelOpenMedia,
        ]);
    }
}

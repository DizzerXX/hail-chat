<?php

namespace Database\Factories;

use App\Models\Faq;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\FaqQuery>
 */
class FaqQueryFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'conversation_id' => null,
            'query_text' => fake()->sentence(),
            'language' => 'ms',
            'matched_faq_id' => null,
        ];
    }

    public function matched(): static
    {
        return $this->state(fn (array $attributes) => [
            'matched_faq_id' => Faq::factory(),
        ]);
    }
}

<?php

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\Faq>
 */
class FaqFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'translation_group' => (string) Str::uuid(),
            'language' => 'ms',
            'question' => fake()->sentence().'?',
            'answer' => fake()->paragraph(),
            'keywords' => fake()->words(3),
            'is_active' => true,
            'usage_count' => 0,
        ];
    }

    public function english(): static
    {
        return $this->state(fn (array $attributes) => [
            'language' => 'en',
        ]);
    }

    public function inGroup(string $translationGroup): static
    {
        return $this->state(fn (array $attributes) => [
            'translation_group' => $translationGroup,
        ]);
    }
}

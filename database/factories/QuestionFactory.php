<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Question;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Question>
 */
final class QuestionFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'external_id' => $this->faker->uuid(),
            'option1' => $this->faker->sentence(6),
            'option2' => $this->faker->sentence(6),
            'expires_at' => now()->addSeconds(60),
            'is_active' => true,
        ];
    }

    public function expired(): static
    {
        return $this->state(['expires_at' => now()->subSeconds(1)]);
    }

    public function inactive(): static
    {
        return $this->state(['is_active' => false]);
    }
}

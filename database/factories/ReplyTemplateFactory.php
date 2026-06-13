<?php

namespace Database\Factories;

use App\Models\ReplyTemplate;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ReplyTemplate>
 */
class ReplyTemplateFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'project_id' => null,
            'name' => fake()->words(2, true),
            'body' => fake()->paragraph(),
            'created_by' => null,
        ];
    }
}

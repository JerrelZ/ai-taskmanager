<?php

namespace Database\Factories;

use App\Enums\ProjectStatus;
use App\Models\Project;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Project>
 */
class ProjectFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $name = fake()->unique()->catchPhrase();

        return [
            'client_id' => null,
            'name' => $name,
            'key' => Project::generateKey($name),
            'color' => fake()->randomElement(['zinc', 'red', 'orange', 'amber', 'green', 'blue', 'indigo', 'purple', 'pink']),
            'description' => fake()->optional()->sentence(),
            'repo_path' => null,
            'stack' => null,
            'context' => null,
            'status' => ProjectStatus::Active,
            'position' => 0,
        ];
    }
}

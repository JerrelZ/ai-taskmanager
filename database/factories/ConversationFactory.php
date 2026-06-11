<?php

namespace Database\Factories;

use App\Enums\ConversationType;
use App\Models\Conversation;
use App\Models\Project;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Conversation>
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
            'type' => ConversationType::Group,
            'name' => fake()->words(2, true),
            'project_id' => null,
        ];
    }

    public function dm(): static
    {
        return $this->state(fn () => ['type' => ConversationType::Dm, 'name' => null]);
    }

    public function forProject(Project $project): static
    {
        return $this->state(fn () => [
            'type' => ConversationType::Project,
            'project_id' => $project->id,
            'name' => $project->name,
        ]);
    }
}

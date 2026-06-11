<?php

namespace Database\Factories;

use App\Enums\TaskPriority;
use App\Enums\TaskStatus;
use App\Models\Project;
use App\Models\Task;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Task>
 */
class TaskFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'project_id' => Project::factory(),
            'parent_id' => null,
            'title' => rtrim(fake()->sentence(4), '.'),
            'description' => fake()->boolean(60) ? fake()->paragraph() : null,
            'status' => fake()->randomElement(TaskStatus::cases()),
            'priority' => fake()->randomElement(TaskPriority::cases()),
            'assignee_id' => fake()->boolean(70) ? User::factory() : null,
            'due_date' => fake()->boolean(50) ? fake()->dateTimeBetween('-1 week', '+3 weeks')->format('Y-m-d') : null,
            'position' => 0,
            'rank' => 0,
            'reviewed_at' => null,
            'created_by' => User::factory(),
        ];
    }

    public function status(TaskStatus $status): static
    {
        return $this->state(fn () => ['status' => $status]);
    }

    public function priority(TaskPriority $priority): static
    {
        return $this->state(fn () => ['priority' => $priority]);
    }

    /**
     * Make this task a subtask of the given parent (inheriting its project).
     */
    public function subtaskOf(Task $parent): static
    {
        return $this->state(fn () => [
            'parent_id' => $parent->id,
            'project_id' => $parent->project_id,
        ]);
    }
}

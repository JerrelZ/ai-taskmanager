<?php

namespace Database\Factories;

use App\Enums\UserRole;
use App\Models\Invitation;
use App\Models\Workspace;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Invitation>
 */
class InvitationFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'workspace_id' => Workspace::query()->value('id') ?? Workspace::factory(),
            'email' => fake()->unique()->safeEmail(),
            'role' => UserRole::Member,
            'client_id' => null,
            'token' => Invitation::generateToken(),
            'invited_by' => null,
            'expires_at' => now()->addDays(7),
            'accepted_at' => null,
        ];
    }

    /**
     * An invitation that can no longer be accepted because it has expired.
     */
    public function expired(): static
    {
        return $this->state(fn () => ['expires_at' => now()->subDay()]);
    }

    /**
     * An invitation that has already been accepted.
     */
    public function accepted(): static
    {
        return $this->state(fn () => ['accepted_at' => now()]);
    }
}

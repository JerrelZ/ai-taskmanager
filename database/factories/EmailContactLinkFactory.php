<?php

namespace Database\Factories;

use App\Models\EmailAccount;
use App\Models\EmailContactLink;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<EmailContactLink>
 */
class EmailContactLinkFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'email_account_id' => EmailAccount::factory(),
            'email' => fake()->unique()->safeEmail(),
            'external_table' => 'customers',
            'external_id_column' => 'id',
            'external_id' => (string) fake()->numberBetween(1, 9999),
            'label' => fake()->name(),
            'linked_by' => null,
        ];
    }
}

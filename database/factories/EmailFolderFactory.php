<?php

namespace Database\Factories;

use App\Models\EmailAccount;
use App\Models\EmailFolder;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<EmailFolder>
 */
class EmailFolderFactory extends Factory
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
            'name' => 'INBOX',
            'uid_validity' => null,
            'last_seen_uid' => 0,
            'synced_at' => null,
        ];
    }
}

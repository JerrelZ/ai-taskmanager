<?php

namespace Database\Factories;

use App\Models\EmailAccount;
use App\Models\Project;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<EmailAccount>
 */
class EmailAccountFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $email = fake()->unique()->safeEmail();

        return [
            'project_id' => Project::factory(),
            'email_address' => $email,
            'imap_host' => 'imap.example.com',
            'imap_port' => 993,
            'imap_encryption' => 'ssl',
            'smtp_host' => 'smtp.example.com',
            'smtp_port' => 465,
            'smtp_encryption' => 'ssl',
            'username' => $email,
            'password' => 'app-password',
            'external_db_dsn' => null,
            'external_api_base_url' => null,
            'external_api_token' => null,
            'is_active' => true,
            'sync_days' => null,
            'last_sync_at' => null,
            'last_sync_error' => null,
        ];
    }
}

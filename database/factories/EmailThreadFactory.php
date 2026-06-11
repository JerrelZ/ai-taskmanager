<?php

namespace Database\Factories;

use App\Models\EmailAccount;
use App\Models\EmailThread;
use App\Models\Project;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<EmailThread>
 */
class EmailThreadFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $subject = fake()->sentence();

        return [
            'email_account_id' => EmailAccount::factory(),
            'project_id' => Project::factory(),
            'subject' => $subject,
            'thread_key' => 'subject:'.md5($subject),
            'ai_category' => null,
            'ai_summary' => null,
            'ai_categorised_at' => null,
            'last_message_at' => now(),
            'message_count' => 0,
            'is_read' => false,
        ];
    }
}

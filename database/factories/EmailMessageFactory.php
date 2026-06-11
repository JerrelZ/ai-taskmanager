<?php

namespace Database\Factories;

use App\Models\EmailAccount;
use App\Models\EmailFolder;
use App\Models\EmailMessage;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<EmailMessage>
 */
class EmailMessageFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $account = EmailAccount::factory();

        return [
            'email_account_id' => $account,
            'email_folder_id' => EmailFolder::factory(),
            'email_thread_id' => null,
            'uid_validity' => 1,
            'uid' => fake()->unique()->numberBetween(1, 1_000_000),
            'message_id' => '<'.fake()->uuid().'@example.com>',
            'in_reply_to' => null,
            'references' => null,
            'raw_path' => null,
            'raw_size' => null,
            'direction' => EmailMessage::DIRECTION_INBOUND,
            'status' => EmailMessage::STATUS_RECEIVED,
            'parse_attempts' => 0,
            'parse_error' => null,
            'from_name' => fake()->name(),
            'from_email' => fake()->safeEmail(),
            'to' => [fake()->safeEmail()],
            'cc' => null,
            'subject' => fake()->sentence(),
            'text_body' => fake()->paragraph(),
            'html_body' => null,
            'headers' => null,
            'sent_at' => now(),
            'received_at' => now(),
        ];
    }
}

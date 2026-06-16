<?php

namespace App\Console\Commands;

use App\Enums\MessengerNotificationMode;
use App\Models\User;
use App\Notifications\MessageDigestNotification;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('messenger:send-digests')]
#[Description('Send periodic message digests to users whose digest interval has elapsed.')]
class SendMessageDigests extends Command
{
    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $url = route('messages.index');
        $sent = 0;

        User::query()
            ->where('messenger_notifications_enabled', true)
            ->where('messenger_notification_mode', MessengerNotificationMode::Digest->value)
            ->each(function (User $user) use ($url, &$sent): void {
                if (! $user->isDueForMessageDigest()) {
                    return;
                }

                $count = $user->unreadMessagesCount();

                $user->forceFill(['messenger_digest_last_sent_at' => now()])->save();

                if ($count < 1) {
                    return;
                }

                $user->notify(new MessageDigestNotification($count, $url));
                $sent++;
            });

        $this->info("Sent {$sent} message digest(s).");

        return self::SUCCESS;
    }
}

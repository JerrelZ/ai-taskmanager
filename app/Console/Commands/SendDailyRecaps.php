<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Notifications\DailyRecapNotification;
use App\Support\DailyRecap;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('recap:send-daily {--force : Send even if a recap was already sent to a user today}')]
#[Description('Send each opted-in user a daily recap e-mail, but only when they have relevant activity.')]
class SendDailyRecaps extends Command
{
    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $force = (bool) $this->option('force');
        $sent = 0;

        User::query()
            ->where('daily_recap_enabled', true)
            ->whereNotNull('email')
            ->each(function (User $user) use ($force, &$sent): void {
                if (! $force && $user->hasReceivedRecapToday()) {
                    return;
                }

                $recap = DailyRecap::for($user);

                if (! $recap['hasActivity']) {
                    return;
                }

                $user->notify(new DailyRecapNotification($recap));
                $user->forceFill(['daily_recap_last_sent_at' => now()])->save();
                $sent++;
            });

        $this->info("Sent {$sent} daily recap(s).");

        return self::SUCCESS;
    }
}

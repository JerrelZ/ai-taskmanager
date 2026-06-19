<?php

use App\Jobs\Email\DispatchEmailSyncs;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Poll every active email account's mailbox. Requires a running scheduler
// (`php artisan schedule:work` locally, or a `* * * * * php artisan schedule:run` cron).
Schedule::job(new DispatchEmailSyncs)->everyFiveMinutes()->withoutOverlapping();

// Send batched message digests to users who opted out of realtime notifications.
// Runs hourly; each user is only notified once their own interval has elapsed.
Schedule::command('messenger:send-digests')->hourly()->withoutOverlapping();

// Send each opted-in user a daily recap of their tasks, deadlines and unread
// chat — but only when they actually have activity (keeps within Resend's
// free tier). Runs once every morning in the app's timezone.
Schedule::command('recap:send-daily')
    ->dailyAt('07:30')
    ->timezone('Europe/Amsterdam')
    ->withoutOverlapping();

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

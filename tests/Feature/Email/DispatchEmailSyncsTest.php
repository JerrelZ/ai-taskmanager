<?php

use App\Jobs\Email\DispatchEmailSyncs;
use App\Jobs\Email\SyncEmailAccount;
use App\Models\EmailAccount;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Bus;

it('dispatches a sync job for active accounts only', function () {
    Bus::fake([SyncEmailAccount::class]);

    $active = EmailAccount::factory()->create(['is_active' => true]);
    EmailAccount::factory()->create(['is_active' => false]);

    (new DispatchEmailSyncs)->handle();

    Bus::assertDispatchedTimes(SyncEmailAccount::class, 1);
    Bus::assertDispatched(SyncEmailAccount::class, fn (SyncEmailAccount $job): bool => $job->emailAccountId === $active->id);
});

it('registers the email sync on the schedule', function () {
    $events = app(Schedule::class)->events();

    $hasEmailSync = collect($events)->contains(
        fn ($event): bool => str_contains((string) $event->description, 'DispatchEmailSyncs')
            || str_contains($event->getSummaryForDisplay(), 'DispatchEmailSyncs'),
    );

    expect($hasEmailSync)->toBeTrue();
});

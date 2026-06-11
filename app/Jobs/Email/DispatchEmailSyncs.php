<?php

namespace App\Jobs\Email;

use App\Models\EmailAccount;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Scheduler entry point: fan a sync job out to every active email account.
 * Each {@see SyncEmailAccount} guards itself with a per-account lock, so this
 * stays safe even if a previous account's sync is still running.
 */
class DispatchEmailSyncs implements ShouldQueue
{
    use Queueable;

    public function handle(): void
    {
        EmailAccount::query()
            ->where('is_active', true)
            ->pluck('id')
            ->each(fn (int $id) => SyncEmailAccount::dispatch($id));
    }
}

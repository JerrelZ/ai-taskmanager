<?php

namespace App\Jobs\Email;

use App\Models\EmailMessage;
use App\Models\EmailThread;
use App\Services\Email\EmailCategoriser;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * AI categorisation of a thread. Best-effort: a failure here never blocks
 * ingestion or parsing — the thread simply stays uncategorised until retried.
 */
class CategoriseEmailThread implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public function __construct(public readonly int $emailThreadId) {}

    public function handle(EmailCategoriser $categoriser): void
    {
        $thread = EmailThread::find($this->emailThreadId);

        if ($thread === null) {
            return;
        }

        $result = $categoriser->categorise($thread);

        $thread->forceFill([
            'ai_category' => $result['category'],
            'ai_summary' => $result['summary'],
            'ai_categorised_at' => now(),
        ])->save();

        // Once categorised, mark the thread's messages as fully processed.
        $thread->messages()
            ->where('status', EmailMessage::STATUS_PARSED)
            ->update(['status' => EmailMessage::STATUS_CATEGORISED]);
    }
}

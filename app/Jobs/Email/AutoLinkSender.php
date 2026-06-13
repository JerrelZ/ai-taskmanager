<?php

namespace App\Jobs\Email;

use App\Models\EmailContactLink;
use App\Models\EmailMessage;
use App\Models\EmailThread;
use App\Services\Email\ContactLinkSuggester;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Auto-triage: when a new inbound email arrives, link the sender to its external
 * database row automatically — but only when there is exactly one confident
 * match, so a human is never second-guessed. Ambiguous senders stay manual.
 */
class AutoLinkSender implements ShouldQueue
{
    use Queueable;

    public function __construct(public readonly int $emailThreadId) {}

    public function handle(ContactLinkSuggester $suggester): void
    {
        $thread = EmailThread::with(['account', 'messages'])->find($this->emailThreadId);
        $account = $thread?->account;

        if ($thread === null || $account === null || blank($account->external_db_dsn)) {
            return;
        }

        $email = $thread->messages
            ->where('direction', EmailMessage::DIRECTION_INBOUND)
            ->last()?->from_email;

        if ($email === null) {
            return;
        }

        $alreadyLinked = EmailContactLink::where('email_account_id', $account->id)
            ->where('email', $email)
            ->exists();

        if ($alreadyLinked) {
            return;
        }

        try {
            $suggestions = $suggester->suggest($account, $email);
        } catch (\Throwable) {
            return;
        }

        // Only auto-link on a single, unambiguous match.
        if (count($suggestions) !== 1) {
            return;
        }

        $match = $suggestions[0];

        EmailContactLink::create([
            'email_account_id' => $account->id,
            'email' => $email,
            'external_table' => $match['table'],
            'external_id_column' => $match['id_column'],
            'external_id' => $match['id'],
            'label' => $match['label'] ?: null,
            'linked_by' => null, // null = automatically linked
        ]);
    }
}

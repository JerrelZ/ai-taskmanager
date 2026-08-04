<?php

namespace App\Jobs\Email;

use App\Models\EmailContactLink;
use App\Models\EmailMessage;
use App\Models\EmailThread;
use App\Services\Email\ContactLinkSuggester;
use App\Services\Email\ExternalProjectApi;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Auto-triage: when a new inbound email arrives, link the sender to their
 * support-API record automatically — but only when there is exactly one
 * confident match, so a human is never second-guessed. Ambiguous senders stay
 * manual.
 */
class AutoLinkSender implements ShouldQueue
{
    use Queueable;

    public function __construct(public readonly int $emailThreadId) {}

    public function handle(ContactLinkSuggester $suggester, ExternalProjectApi $api): void
    {
        $thread = EmailThread::with(['account', 'messages'])->find($this->emailThreadId);
        $account = $thread?->account;

        if ($thread === null || $account === null || ! $api->configured($account)) {
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
            'external_resource' => $match['resource'],
            'external_id' => $match['id'],
            'label' => $match['label'] ?: null,
            'linked_by' => null, // null = automatically linked
        ]);
    }
}

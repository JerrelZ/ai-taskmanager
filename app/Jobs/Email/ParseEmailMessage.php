<?php

namespace App\Jobs\Email;

use App\Models\EmailMessage;
use App\Models\EmailThread;
use App\Models\User;
use App\Notifications\InboxNotification;
use App\Services\Email\MailParser;
use App\Services\Email\RawEmailStore;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

/**
 * Parses a durably-stored raw message (MIME + threading) and assembles its thread.
 *
 * Runs only after ingestion committed the raw row, so it can fail and retry
 * forever without ever touching the mail server or risking message loss. A
 * permanently failing parse leaves the raw row intact with status parse_failed.
 */
class ParseEmailMessage implements ShouldQueue
{
    use Queueable;

    public int $tries = 5;

    public function __construct(public readonly int $emailMessageId) {}

    public function handle(RawEmailStore $store, MailParser $parser): void
    {
        $message = EmailMessage::find($this->emailMessageId);

        if ($message === null || in_array($message->status, [EmailMessage::STATUS_PARSED, EmailMessage::STATUS_CATEGORISED], true)) {
            return;
        }

        try {
            $parsed = $parser->parse($store->get($message->raw_path));
            $thread = $this->resolveThread($message, $parsed, $parser);

            DB::transaction(function () use ($message, $parsed, $thread): void {
                $message->forceFill([
                    'email_thread_id' => $thread->id,
                    'message_id' => $message->message_id ?? $this->wrapId($parsed['message_id']),
                    'in_reply_to' => $parsed['in_reply_to'],
                    'references' => $parsed['references'],
                    'from_name' => $parsed['from_name'],
                    'from_email' => $parsed['from_email'],
                    'to' => $parsed['to'],
                    'cc' => $parsed['cc'],
                    'subject' => $parsed['subject'],
                    'text_body' => $parsed['text_body'],
                    'html_body' => $parsed['html_body'],
                    'sent_at' => $parsed['sent_at'] ?? $message->received_at,
                    'status' => EmailMessage::STATUS_PARSED,
                    'parse_error' => null,
                ])->save();

                $this->refreshThreadAggregates($thread);
            });

            CategoriseEmailThread::dispatch($thread->id);

            $this->notifyAssignee($thread, $message);
        } catch (Throwable $e) {
            $this->recordFailure($message, $e);

            throw $e;
        }
    }

    /**
     * @param  array<string, mixed>  $parsed
     */
    private function resolveThread(EmailMessage $message, array $parsed, MailParser $parser): EmailThread
    {
        $account = $message->account;

        // The conversation root: first reference, else the parent, else this message itself.
        $rootId = $parsed['reference_ids'][0]
            ?? $parsed['in_reply_to']
            ?? $parsed['message_id']
            ?? $parser->normaliseId($message->message_id);

        $threadKey = $rootId !== null
            ? 'root:'.$rootId
            : 'subj:'.md5($this->normaliseSubject($parsed['subject']));

        return EmailThread::firstOrCreate(
            ['email_account_id' => $account->id, 'thread_key' => $threadKey],
            [
                'project_id' => $account->project_id,
                'subject' => $this->normaliseSubject($parsed['subject']),
                'last_message_at' => $parsed['sent_at'] ?? $message->received_at,
            ],
        );
    }

    /**
     * Notify the thread's assignee when a new inbound message lands on a thread
     * they are responsible for.
     */
    private function notifyAssignee(EmailThread $thread, EmailMessage $message): void
    {
        if ($message->direction !== EmailMessage::DIRECTION_INBOUND || $thread->assignee_id === null) {
            return;
        }

        $assignee = User::find($thread->assignee_id);

        $assignee?->notify(new InboxNotification(
            title: __('Nieuw bericht in toegewezen gesprek'),
            body: ($message->from_email ?: __('Onbekend')).' — '.($thread->subject ?: __('(geen onderwerp)')),
            url: route('projects.inbox', $thread->project_id).'?selectedThreadId='.$thread->id,
            icon: 'envelope',
        ));
    }

    private function refreshThreadAggregates(EmailThread $thread): void
    {
        $thread->forceFill([
            'message_count' => $thread->messages()->count(),
            'last_message_at' => $thread->messages()->max('sent_at') ?? $thread->last_message_at,
        ])->save();
    }

    private function recordFailure(EmailMessage $message, Throwable $e): void
    {
        $attempts = $message->parse_attempts + 1;

        $message->forceFill([
            'parse_attempts' => $attempts,
            'parse_error' => Str::limit($e->getMessage(), 1000, ''),
            'status' => $attempts >= EmailMessage::MAX_PARSE_ATTEMPTS
                ? EmailMessage::STATUS_PARSE_FAILED
                : $message->status,
        ])->save();
    }

    private function wrapId(?string $bareId): ?string
    {
        return $bareId === null ? null : "<{$bareId}>";
    }

    private function normaliseSubject(?string $subject): string
    {
        $subject = trim((string) $subject);

        // Strip any run of reply/forward prefixes (Re:, Fwd:, Fw:, Aw:, Antw:).
        $subject = preg_replace('/^(\s*(re|fwd?|aw|antw)\s*:\s*)+/i', '', $subject) ?? $subject;

        return trim($subject);
    }
}

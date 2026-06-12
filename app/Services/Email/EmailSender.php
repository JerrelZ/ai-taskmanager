<?php

namespace App\Services\Email;

use App\Mail\EmailReply;
use App\Models\EmailAccount;
use App\Models\EmailFolder;
use App\Models\EmailMessage;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;

/**
 * Sends replies on an email thread via the account's own SMTP credentials.
 *
 * No-loss ordering: the outbound message is recorded durably BEFORE the SMTP
 * send, so a failed send leaves a retryable record rather than a lost draft.
 * After a successful send the message is appended to the IMAP "Sent" folder
 * (best-effort) so it also shows up in Gmail/Outlook; the message-id dedupe in
 * the sync pipeline keeps the later Sent-sync from duplicating it.
 */
class EmailSender
{
    /** Sentinel UIDVALIDITY for app-originated messages that never came from a folder fetch. */
    private const OUTBOUND_UID_VALIDITY = 0;

    public function __construct(
        private readonly RawEmailStore $store,
        private readonly MailParser $parser,
        private readonly ImapClientFactory $imap,
    ) {}

    public function reply(EmailMessage $inReplyTo, string $body): EmailMessage
    {
        $account = $inReplyTo->account;
        $recipient = $inReplyTo->from_email;

        if (blank($recipient)) {
            throw new \InvalidArgumentException('The message being replied to has no sender address.');
        }

        $bareId = $this->generateMessageId($account);
        $subject = $this->replySubject($inReplyTo->subject);
        $parentBareId = $this->parser->normaliseId($inReplyTo->message_id);
        $references = $this->buildReferences($inReplyTo, $parentBareId);

        $outbound = $this->recordOutbound($account, $inReplyTo, $recipient, $subject, $body, $bareId, $parentBareId, $references);

        $this->sendSmtp($account, $recipient, $subject, $body, $bareId, $parentBareId, $references);

        $this->appendToSent($account, $outbound);

        $this->refreshThread($inReplyTo);

        return $outbound;
    }

    private function recordOutbound(EmailAccount $account, EmailMessage $inReplyTo, string $recipient, string $subject, string $body, string $bareId, ?string $parentBareId, array $references): EmailMessage
    {
        $folder = EmailFolder::firstOrCreate(
            ['email_account_id' => $account->id, 'name' => 'Sent'],
            ['uid_validity' => self::OUTBOUND_UID_VALIDITY, 'last_seen_uid' => 0],
        );

        $uid = (int) EmailMessage::where('email_account_id', $account->id)
            ->where('email_folder_id', $folder->id)
            ->where('uid_validity', self::OUTBOUND_UID_VALIDITY)
            ->max('uid') + 1;

        $messageId = "<{$bareId}>";
        $raw = $this->buildRaw($account->email_address, $recipient, $subject, $body, $messageId, $parentBareId, $references);
        $path = $this->store->store($account->id, $folder->name, self::OUTBOUND_UID_VALIDITY, $uid, $raw);

        return EmailMessage::create([
            'email_account_id' => $account->id,
            'email_folder_id' => $folder->id,
            'email_thread_id' => $inReplyTo->email_thread_id,
            'uid_validity' => self::OUTBOUND_UID_VALIDITY,
            'uid' => $uid,
            'message_id' => $messageId,
            'in_reply_to' => $parentBareId,
            'references' => implode(' ', array_map(fn (string $id): string => "<{$id}>", $references)) ?: null,
            'raw_path' => $path,
            'raw_size' => strlen($raw),
            'direction' => EmailMessage::DIRECTION_OUTBOUND,
            'status' => EmailMessage::STATUS_PARSED,
            'from_email' => $account->email_address,
            'to' => [$recipient],
            'subject' => $subject,
            'text_body' => $body,
            'sent_at' => now(),
            'received_at' => now(),
        ]);
    }

    private function sendSmtp(EmailAccount $account, string $recipient, string $subject, string $body, string $bareId, ?string $parentBareId, array $references): void
    {
        $mailer = "email_acct_{$account->id}";

        // Testing escape hatch: write the reply to the log instead of sending it.
        if (Config::get('services.email.log_only')) {
            $mailer = 'log';
        } else {
            Config::set("mail.mailers.{$mailer}", [
                'transport' => 'smtp',
                'host' => $account->smtp_host,
                'port' => $account->smtp_port,
                'encryption' => $account->smtp_encryption === 'none' ? null : $account->smtp_encryption,
                'username' => $account->username,
                'password' => $account->password,
                'timeout' => 15,
            ]);
        }

        Mail::mailer($mailer)->send(new EmailReply(
            fromAddress: $account->email_address,
            toAddress: $recipient,
            subjectLine: $subject,
            bodyText: $body,
            messageId: $bareId,
            inReplyTo: $parentBareId,
            references: $references,
        ));
    }

    private function appendToSent(EmailAccount $account, EmailMessage $outbound): void
    {
        // In log-only testing mode we never touch the real mailbox.
        if (Config::get('services.email.log_only')) {
            return;
        }

        try {
            $raw = $this->store->get($outbound->raw_path);
            $connection = $this->imap->connect($account);

            try {
                $connection->append('Sent', $raw);
            } finally {
                $connection->disconnect();
            }
        } catch (\Throwable) {
            // Best-effort: the message is already recorded locally and was sent.
        }
    }

    private function refreshThread(EmailMessage $inReplyTo): void
    {
        $thread = $inReplyTo->thread;

        if ($thread === null) {
            return;
        }

        $thread->forceFill([
            'message_count' => $thread->messages()->count(),
            'last_message_at' => $thread->messages()->max('sent_at') ?? $thread->last_message_at,
        ])->save();
    }

    /**
     * @param  array<int, string>  $references
     */
    private function buildReferences(EmailMessage $inReplyTo, ?string $parentBareId): array
    {
        $ids = $this->parser->extractIds((string) $inReplyTo->references);

        if ($parentBareId !== null) {
            $ids[] = $parentBareId;
        }

        return array_values(array_unique(array_filter($ids)));
    }

    private function generateMessageId(EmailAccount $account): string
    {
        $domain = Str::after($account->email_address, '@') ?: 'localhost';

        return Str::uuid()->toString().'@'.$domain;
    }

    private function replySubject(?string $subject): string
    {
        $subject = trim((string) $subject);

        if ($subject === '') {
            return 'Re:';
        }

        return Str::startsWith(Str::lower($subject), 're:') ? $subject : "Re: {$subject}";
    }

    /**
     * @param  array<int, string>  $references
     */
    private function buildRaw(string $from, string $to, string $subject, string $body, string $messageId, ?string $parentBareId, array $references): string
    {
        $headers = [
            "Message-ID: {$messageId}",
            'Date: '.now()->toRfc2822String(),
            "From: {$from}",
            "To: {$to}",
            "Subject: {$subject}",
        ];

        if ($parentBareId !== null) {
            $headers[] = "In-Reply-To: <{$parentBareId}>";
        }

        if ($references !== []) {
            $headers[] = 'References: '.implode(' ', array_map(fn (string $id): string => "<{$id}>", $references));
        }

        $headers[] = 'Content-Type: text/plain; charset=utf-8';

        return implode("\r\n", $headers)."\r\n\r\n".$body;
    }
}

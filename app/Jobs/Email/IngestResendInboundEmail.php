<?php

namespace App\Jobs\Email;

use App\Models\EmailAccount;
use App\Models\EmailFolder;
use App\Models\EmailMessage;
use App\Services\Email\RawEmailStore;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Ingests one inbound email delivered by a Resend `email.received` webhook.
 *
 * The webhook only carries metadata, so we fetch the full message (and a signed
 * download URL for its raw RFC822 source) from Resend's Received Emails API, then
 * hand the raw source to the same parse pipeline IMAP-synced mail flows through.
 *
 * Idempotent: a redelivered webhook is dropped via the unique provider_email_id.
 *
 * @see https://resend.com/docs/api-reference/emails/retrieve-received-email
 */
class IngestResendInboundEmail implements ShouldQueue
{
    use Queueable;

    public int $tries = 5;

    /**
     * Folder name the synthetic Resend "mailbox" lands in. Kept distinct from the
     * IMAP folders (INBOX/Sent) so a configured account can use both without the
     * sync watermark ever touching webhook rows.
     */
    private const FOLDER = 'Resend';

    /**
     * @param  list<string>  $recipients  Every to/cc/bcc address on the webhook, used to find the receiving account.
     */
    public function __construct(
        public readonly string $resendEmailId,
        public readonly array $recipients,
    ) {}

    /**
     * @return array<int, int>
     */
    public function backoff(): array
    {
        return [30, 120, 300, 600];
    }

    public function handle(RawEmailStore $store): void
    {
        // Redelivery of an already-ingested email: nothing to do.
        if (EmailMessage::where('provider_email_id', $this->resendEmailId)->exists()) {
            return;
        }

        $account = $this->resolveAccount();

        if ($account === null) {
            return;
        }

        $received = $this->fetchReceivedEmail();
        $downloadUrl = data_get($received, 'raw.download_url');

        if (! is_string($downloadUrl) || $downloadUrl === '') {
            throw new RuntimeException("Resend received email {$this->resendEmailId} has no raw download URL.");
        }

        $raw = $this->downloadRaw($downloadUrl);
        $messageId = is_string($received['message_id'] ?? null) ? $received['message_id'] : null;

        $folder = EmailFolder::firstOrCreate(
            ['email_account_id' => $account->id, 'name' => self::FOLDER],
            ['uid_validity' => null, 'last_seen_uid' => 0],
        );

        $path = $store->storeProvider($account->id, EmailMessage::PROVIDER_RESEND, $this->resendEmailId, $raw);

        DB::transaction(function () use ($account, $folder, $messageId, $path, $raw): void {
            $message = EmailMessage::create([
                'email_account_id' => $account->id,
                'email_folder_id' => $folder->id,
                'provider' => EmailMessage::PROVIDER_RESEND,
                'provider_email_id' => $this->resendEmailId,
                'uid_validity' => null,
                'uid' => null,
                'message_id' => $messageId,
                'raw_path' => $path,
                'raw_size' => strlen($raw),
                'direction' => EmailMessage::DIRECTION_INBOUND,
                'status' => EmailMessage::STATUS_RECEIVED,
                'received_at' => now(),
            ]);

            DB::afterCommit(fn () => ParseEmailMessage::dispatch($message->id));
        });
    }

    /**
     * The active account whose address the email was sent to.
     */
    private function resolveAccount(): ?EmailAccount
    {
        $recipients = array_map('strtolower', $this->recipients);

        if ($recipients === []) {
            return null;
        }

        return EmailAccount::query()
            ->where('is_active', true)
            ->whereIn(DB::raw('LOWER(email_address)'), $recipients)
            ->first();
    }

    /**
     * @return array<string, mixed>
     */
    private function fetchReceivedEmail(): array
    {
        $key = config('services.resend.key');

        if (! is_string($key) || $key === '') {
            throw new RuntimeException('RESEND_API_KEY is not configured.');
        }

        $response = Http::withToken($key)
            ->acceptJson()
            ->get("https://api.resend.com/emails/receiving/{$this->resendEmailId}");

        if (! $response->successful()) {
            throw new RuntimeException("Resend received-email fetch failed (HTTP {$response->status()}).");
        }

        return $response->json();
    }

    private function downloadRaw(string $url): string
    {
        $response = Http::get($url);

        if (! $response->successful()) {
            throw new RuntimeException("Resend raw download failed (HTTP {$response->status()}).");
        }

        return $response->body();
    }
}

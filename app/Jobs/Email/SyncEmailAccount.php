<?php

namespace App\Jobs\Email;

use App\Models\EmailAccount;
use App\Models\EmailFolder;
use App\Models\EmailMessage;
use App\Services\Email\ImapClientFactory;
use App\Services\Email\ImapConnection;
use App\Services\Email\RawEmailStore;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Non-destructive, idempotent ingestion of an account's mailbox.
 *
 * Guarantees no email is ever lost: the raw RFC822 source is persisted and the
 * per-folder watermark is advanced inside the SAME transaction as the row insert,
 * so a crash mid-folder leaves the watermark at the last committed UID and every
 * later UID is simply re-fetched on the next run. The unique (account, folder,
 * uid_validity, uid) index makes re-fetching idempotent.
 */
class SyncEmailAccount implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    /**
     * Top-level folder roots never ingested: server-side wastebins and drafts
     * hold nothing we want in the inbox. Matched case-insensitively against the
     * first path segment, so e.g. "Trash.Subfolder" is excluded too.
     *
     * @var array<int, string>
     */
    private const EXCLUDED_ROOTS = ['Trash', 'Junk', 'Drafts'];

    /**
     * How long the lock is held; longer than a realistic sync, released in finally.
     */
    private const LOCK_SECONDS = 600;

    public function __construct(public readonly int $emailAccountId) {}

    /**
     * @return array<int, int>
     */
    public function backoff(): array
    {
        return [60, 300, 900];
    }

    public function handle(ImapClientFactory $factory, RawEmailStore $store): void
    {
        $account = EmailAccount::find($this->emailAccountId);

        if ($account === null || ! $account->is_active) {
            return;
        }

        $lock = Cache::lock("email-sync-{$account->id}", self::LOCK_SECONDS);

        // Another sync for this account is already running; let it finish.
        if (! $lock->get()) {
            return;
        }

        try {
            $connection = $factory->connect($account);

            try {
                foreach ($this->foldersToSync($connection) as $folderName) {
                    $this->syncFolder($account, $connection, $store, $folderName);
                }

                $account->forceFill(['last_sync_at' => now(), 'last_sync_error' => null])->save();
            } finally {
                $connection->disconnect();
            }
        } finally {
            $lock->release();
        }
    }

    /**
     * Every server folder we ingest, excluded roots removed, with INBOX first so
     * it acts as the required canary (its failure fails and retries the job).
     *
     * @return array<int, string>
     */
    private function foldersToSync(ImapConnection $connection): array
    {
        $folders = array_values(array_filter(
            $connection->listFolders(),
            fn (string $path): bool => ! $this->isExcluded($path),
        ));

        $rest = array_values(array_filter($folders, fn (string $path): bool => $path !== 'INBOX'));

        return ['INBOX', ...$rest];
    }

    private function isExcluded(string $folderName): bool
    {
        return in_array(mb_strtolower($this->rootSegment($folderName)), array_map(mb_strtolower(...), self::EXCLUDED_ROOTS), true);
    }

    /**
     * The first path segment, regardless of the server's hierarchy delimiter.
     */
    private function rootSegment(string $folderName): string
    {
        return preg_split('#[./]#', $folderName)[0] ?? $folderName;
    }

    private function syncFolder(EmailAccount $account, ImapConnection $connection, RawEmailStore $store, string $folderName): void
    {
        try {
            $uidValidity = $connection->selectFolder($folderName);
        } catch (Throwable $e) {
            // A missing optional folder (e.g. provider-specific Sent name) must not
            // block INBOX. INBOX failures still propagate to fail/retry the job.
            if ($folderName === 'INBOX') {
                throw $e;
            }

            return;
        }

        $folder = EmailFolder::firstOrCreate(
            ['email_account_id' => $account->id, 'name' => $folderName],
            ['uid_validity' => $uidValidity, 'last_seen_uid' => 0],
        );

        // UIDVALIDITY handling: a changed epoch invalidates the old watermark.
        if ($folder->uid_validity === null) {
            $folder->forceFill(['uid_validity' => $uidValidity])->save();
        } elseif ((int) $folder->uid_validity !== $uidValidity) {
            $folder->forceFill(['uid_validity' => $uidValidity, 'last_seen_uid' => 0])->save();
        }

        // Bound the very first backfill of a folder to the account's configured
        // window. Once a watermark exists, every later UID is fetched regardless.
        $since = ($folder->last_seen_uid === 0 && $account->sync_days !== null)
            ? now()->subDays($account->sync_days)->startOfDay()
            : null;

        foreach ($connection->fetchUidsGreaterThan($folder->last_seen_uid, $since) as $uid) {
            $raw = $connection->fetchRaw($uid);
            $this->ingestOne($account, $store, $folder, $uid, $raw);
        }

        $folder->forceFill(['synced_at' => now()])->save();
    }

    /**
     * Persist one message: raw first, row + watermark in one transaction, parse after commit.
     */
    private function ingestOne(EmailAccount $account, RawEmailStore $store, EmailFolder $folder, int $uid, string $raw): void
    {
        $uidValidity = (int) $folder->uid_validity;

        $alreadyIngested = EmailMessage::query()
            ->where('email_account_id', $account->id)
            ->where('email_folder_id', $folder->id)
            ->where('uid_validity', $uidValidity)
            ->where('uid', $uid)
            ->exists();

        if ($alreadyIngested) {
            // Idempotent re-run: ensure the watermark reflects what we already have.
            $this->advanceWatermark($folder, $uid);

            return;
        }

        $messageId = $this->peekMessageId($raw);

        // Skip our own already-recorded outbound replies reappearing in Sent.
        // Message-IDs are globally unique, so this can never drop a distinct email.
        if ($messageId !== null && EmailMessage::query()
            ->where('email_account_id', $account->id)
            ->where('message_id', $messageId)
            ->exists()) {
            $this->advanceWatermark($folder, $uid);

            return;
        }

        $path = $store->store($account->id, $folder->name, $uidValidity, $uid, $raw);

        DB::transaction(function () use ($account, $folder, $uid, $uidValidity, $messageId, $path, $raw): void {
            $message = EmailMessage::create([
                'email_account_id' => $account->id,
                'email_folder_id' => $folder->id,
                'uid_validity' => $uidValidity,
                'uid' => $uid,
                'message_id' => $messageId,
                'raw_path' => $path,
                'raw_size' => strlen($raw),
                'direction' => $this->directionForFolder($folder->name),
                'status' => EmailMessage::STATUS_RECEIVED,
                'received_at' => now(),
            ]);

            $folder->forceFill(['last_seen_uid' => $uid])->save();

            DB::afterCommit(fn () => ParseEmailMessage::dispatch($message->id));
        });
    }

    /**
     * Messages pulled from the Sent folder are our own outgoing mail; everything
     * else (INBOX) is genuinely received. Direction drives which side of a thread
     * a message renders on, and gates inbound-only work (auto-link, notifications).
     */
    private function directionForFolder(string $folderName): string
    {
        return $this->rootSegment($folderName) === 'Sent'
            ? EmailMessage::DIRECTION_OUTBOUND
            : EmailMessage::DIRECTION_INBOUND;
    }

    private function advanceWatermark(EmailFolder $folder, int $uid): void
    {
        if ($uid > $folder->last_seen_uid) {
            $folder->forceFill(['last_seen_uid' => $uid])->save();
        }
    }

    /**
     * Cheap Message-ID extraction from raw headers without a full MIME parse.
     */
    private function peekMessageId(string $raw): ?string
    {
        if (preg_match('/^Message-ID:\s*(<[^>]+>)/im', $raw, $matches) === 1) {
            return trim($matches[1]);
        }

        return null;
    }

    public function failed(Throwable $exception): void
    {
        EmailAccount::where('id', $this->emailAccountId)
            ->update(['last_sync_error' => $exception->getMessage()]);
    }
}

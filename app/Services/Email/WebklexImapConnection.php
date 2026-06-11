<?php

namespace App\Services\Email;

use Webklex\PHPIMAP\Client;
use Webklex\PHPIMAP\Folder;
use Webklex\PHPIMAP\Query\WhereQuery;

/**
 * Read-only IMAP session backed by webklex/php-imap.
 *
 * All reads use PEEK (leaveUnread) so the \Seen flag is never touched, and no
 * destructive command is ever issued. {@see append()} is the single write path.
 */
class WebklexImapConnection implements ImapConnection
{
    private ?Folder $folder = null;

    public function __construct(private readonly Client $client) {}

    public function selectFolder(string $folder): int
    {
        $this->folder = $this->client->getFolder($folder);

        // EXAMINE opens the folder read-only and returns its status.
        $status = $this->folder->examine();

        return (int) ($status['uidvalidity'] ?? 0);
    }

    public function fetchUidsGreaterThan(int $uid): array
    {
        $messages = $this->query()
            ->leaveUnread()
            ->setFetchBody(false)
            ->setFetchFlags(false)
            ->whereUid(($uid + 1).':*')
            ->get();

        // IMAP "n:*" always returns the highest message even when none are >= n,
        // so we filter strictly greater than the watermark.
        $uids = $messages
            ->map(fn ($message): int => (int) $message->uid)
            ->filter(fn (int $candidate): bool => $candidate > $uid)
            ->sort()
            ->values()
            ->all();

        return $uids;
    }

    public function fetchRaw(int $uid): string
    {
        $message = $this->query()
            ->leaveUnread()
            ->setFetchBody(true)
            ->setFetchFlags(false)
            ->whereUid($uid)
            ->get()
            ->first();

        if ($message === null) {
            throw new \RuntimeException("Message with UID {$uid} not found in selected folder.");
        }

        return $message->getHeader()->raw."\r\n\r\n".$message->getRawBody();
    }

    public function append(string $folder, string $rawMessage): void
    {
        $this->client->getFolder($folder)->appendMessage($rawMessage, ['\\Seen']);
    }

    public function disconnect(): void
    {
        $this->client->disconnect();
    }

    private function query(): WhereQuery
    {
        if ($this->folder === null) {
            throw new \LogicException('No folder selected. Call selectFolder() first.');
        }

        return $this->folder->query();
    }
}

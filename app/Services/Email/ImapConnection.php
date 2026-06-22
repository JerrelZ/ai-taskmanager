<?php

namespace App\Services\Email;

use Carbon\CarbonInterface;

/**
 * A read-only IMAP session opened for a single account.
 *
 * Implementations MUST NOT expunge, delete, move or flag messages on the server.
 * The only permitted write is {@see self::append()} (append-only, e.g. to "Sent").
 */
interface ImapConnection
{
    /**
     * Every mailbox folder path on the server (flat, including nested folders).
     *
     * @return array<int, string>
     */
    public function listFolders(): array;

    /**
     * Open a folder in read-only (EXAMINE) mode and return its UIDVALIDITY.
     */
    public function selectFolder(string $folder): int;

    /**
     * UIDs in the currently selected folder strictly greater than $uid, ascending.
     *
     * When $since is given, only messages received on or after that date are
     * returned. This bounds the initial backfill to a recent window.
     *
     * @return array<int, int>
     */
    public function fetchUidsGreaterThan(int $uid, ?CarbonInterface $since = null): array;

    /**
     * The raw RFC822 source of a single message by UID in the selected folder.
     * Fetched with PEEK so the \Seen flag is never altered.
     */
    public function fetchRaw(int $uid): string;

    /**
     * Append a raw RFC822 message to a folder (the only allowed write operation).
     */
    public function append(string $folder, string $rawMessage): void;

    /**
     * Close the underlying connection.
     */
    public function disconnect(): void;
}

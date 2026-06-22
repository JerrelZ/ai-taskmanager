<?php

namespace App\Services\Email;

use Illuminate\Support\Facades\Storage;

/**
 * Durable storage for the raw RFC822 source of every message.
 *
 * The DB row is the source of truth for "have we ingested this UID"; the .eml
 * file is the recoverable payload that lets the parse phase be retried forever
 * without ever re-touching the mail server.
 */
class RawEmailStore
{
    private const DISK = 'local';

    /**
     * Persist a raw message and return its storage path.
     */
    public function store(int $accountId, string $folder, int $uidValidity, int $uid, string $raw): string
    {
        $path = $this->path($accountId, $folder, $uidValidity, $uid);

        Storage::disk(self::DISK)->put($path, $raw);

        return $path;
    }

    /**
     * Persist a raw message received from a provider webhook (no IMAP folder/UID)
     * and return its storage path. Keyed by the provider's own message id.
     */
    public function storeProvider(int $accountId, string $provider, string $providerEmailId, string $raw): string
    {
        $safeProvider = preg_replace('/[^A-Za-z0-9]+/', '_', $provider) ?: 'provider';
        $safeId = preg_replace('/[^A-Za-z0-9._-]+/', '_', $providerEmailId) ?: 'message';

        $path = "email/raw/{$accountId}/{$safeProvider}/{$safeId}.eml";

        Storage::disk(self::DISK)->put($path, $raw);

        return $path;
    }

    /**
     * Read a previously stored raw message.
     */
    public function get(string $path): string
    {
        $raw = Storage::disk(self::DISK)->get($path);

        if ($raw === null) {
            throw new \RuntimeException("Raw email not found at [{$path}].");
        }

        return $raw;
    }

    private function path(int $accountId, string $folder, int $uidValidity, int $uid): string
    {
        $safeFolder = preg_replace('/[^A-Za-z0-9]+/', '_', $folder) ?: 'folder';

        return "email/raw/{$accountId}/{$safeFolder}/{$uidValidity}/{$uid}.eml";
    }
}

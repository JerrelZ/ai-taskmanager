<?php

namespace Tests\Support\Email;

use App\Services\Email\ImapConnection;

/**
 * In-memory IMAP session for tests. Lets a test seed folders with UID/raw pairs,
 * simulate a UIDVALIDITY change, and force a mid-sync failure on a specific UID.
 */
class FakeImapConnection implements ImapConnection
{
    /** @var array<string, array{uidvalidity: int, messages: array<int, string>}> */
    public array $folders = [];

    /** @var array<int, array{folder: string, raw: string}> */
    public array $appended = [];

    /**
     * When set, fetchRaw() throws the first time it is asked for this UID,
     * simulating a crash mid-sync. Cleared after firing once.
     */
    public ?int $failOnUid = null;

    public bool $disconnected = false;

    private ?string $current = null;

    public function seed(string $folder, int $uid, string $raw, int $uidValidity = 1): self
    {
        $this->folders[$folder] ??= ['uidvalidity' => $uidValidity, 'messages' => []];
        $this->folders[$folder]['uidvalidity'] = $uidValidity;
        $this->folders[$folder]['messages'][$uid] = $raw;

        return $this;
    }

    public function setUidValidity(string $folder, int $uidValidity): self
    {
        $this->folders[$folder] ??= ['uidvalidity' => $uidValidity, 'messages' => []];
        $this->folders[$folder]['uidvalidity'] = $uidValidity;

        return $this;
    }

    public function selectFolder(string $folder): int
    {
        $this->current = $folder;
        $this->folders[$folder] ??= ['uidvalidity' => 1, 'messages' => []];

        return $this->folders[$folder]['uidvalidity'];
    }

    public function fetchUidsGreaterThan(int $uid): array
    {
        $uids = array_keys($this->currentFolder()['messages']);
        $uids = array_values(array_filter($uids, fn (int $candidate): bool => $candidate > $uid));
        sort($uids);

        return $uids;
    }

    public function fetchRaw(int $uid): string
    {
        if ($this->failOnUid === $uid) {
            $this->failOnUid = null;

            throw new \RuntimeException("Simulated mid-sync failure fetching UID {$uid}.");
        }

        $messages = $this->currentFolder()['messages'];

        if (! array_key_exists($uid, $messages)) {
            throw new \RuntimeException("Message with UID {$uid} not found.");
        }

        return $messages[$uid];
    }

    public function append(string $folder, string $rawMessage): void
    {
        $this->appended[] = ['folder' => $folder, 'raw' => $rawMessage];

        $this->folders[$folder] ??= ['uidvalidity' => 1, 'messages' => []];
        $existing = array_keys($this->folders[$folder]['messages']);
        $nextUid = ($existing === [] ? 0 : max($existing)) + 1;
        $this->folders[$folder]['messages'][$nextUid] = $rawMessage;
    }

    public function disconnect(): void
    {
        $this->disconnected = true;
    }

    /**
     * @return array{uidvalidity: int, messages: array<int, string>}
     */
    private function currentFolder(): array
    {
        if ($this->current === null) {
            throw new \LogicException('No folder selected.');
        }

        return $this->folders[$this->current];
    }
}

<?php

namespace Tests\Support\Email;

use App\Models\EmailAccount;
use App\Services\Email\ImapClientFactory;
use App\Services\Email\ImapConnection;

/**
 * Returns a pre-built {@see FakeImapConnection} per account id, so a test can
 * inspect exactly what the sync pipeline read and appended.
 */
class FakeImapClientFactory implements ImapClientFactory
{
    /** @var array<int, FakeImapConnection> */
    private array $connections = [];

    public function for(EmailAccount $account): FakeImapConnection
    {
        return $this->connections[$account->id] ??= new FakeImapConnection;
    }

    public function connect(EmailAccount $account): ImapConnection
    {
        return $this->connections[$account->id] ??= new FakeImapConnection;
    }
}

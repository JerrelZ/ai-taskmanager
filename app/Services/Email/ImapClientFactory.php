<?php

namespace App\Services\Email;

use App\Models\EmailAccount;

/**
 * Builds a connected, read-only {@see ImapConnection} for an account.
 * Bound in the container so tests can swap in a fake implementation.
 */
interface ImapClientFactory
{
    public function connect(EmailAccount $account): ImapConnection;
}

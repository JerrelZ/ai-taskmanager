<?php

namespace App\Services\Email;

use App\Models\EmailAccount;
use Webklex\PHPIMAP\ClientManager;
use Webklex\PHPIMAP\IMAP;

class WebklexImapClientFactory implements ImapClientFactory
{
    public function __construct(private readonly ClientManager $clientManager) {}

    public function connect(EmailAccount $account): ImapConnection
    {
        $client = $this->clientManager->make([
            'host' => $account->imap_host,
            'port' => $account->imap_port,
            'encryption' => $this->encryption($account->imap_encryption),
            'validate_cert' => true,
            'username' => $account->username,
            'password' => $account->password,
            'protocol' => 'imap',
            // Fetch bodies with PEEK by default so reads never set the \Seen flag.
            'options' => [
                'fetch' => IMAP::FT_PEEK,
            ],
        ]);

        $client->connect();

        return new WebklexImapConnection($client);
    }

    /**
     * Map our stored encryption value to what webklex expects.
     */
    private function encryption(string $encryption): string|bool
    {
        return match (strtolower($encryption)) {
            'ssl' => 'ssl',
            'tls' => 'tls',
            'starttls' => 'starttls',
            default => false,
        };
    }
}

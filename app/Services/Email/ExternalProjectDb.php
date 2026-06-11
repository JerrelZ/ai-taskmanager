<?php

namespace App\Services\Email;

use App\Models\EmailAccount;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * On-demand, read-only access to a project's external MySQL database.
 *
 * The connection is registered in config at runtime only (never persisted to
 * config/database.php) and purged after use. Read-only is enforced in depth:
 *  1. The configured DB user is expected to hold SELECT-only grants (the real guarantee).
 *  2. Every statement must begin with a read-only verb (defence-in-depth).
 *  3. A short connect timeout, and the connection is torn down immediately.
 */
class ExternalProjectDb
{
    /**
     * Run a read-only query against the account's external database.
     *
     * @param  array<int, mixed>  $bindings
     * @return array<int, \stdClass>
     */
    public function select(EmailAccount $account, string $sql, array $bindings = []): array
    {
        $dsn = $account->external_db_dsn;

        if (blank($dsn)) {
            throw new InvalidArgumentException('No external database configured for this account.');
        }

        $this->assertReadOnly($sql);

        $name = "email_ext_{$account->id}";

        config(["database.connections.{$name}" => [
            'driver' => 'mysql',
            'host' => $dsn['host'] ?? '127.0.0.1',
            'port' => $dsn['port'] ?? 3306,
            'database' => $dsn['database'] ?? '',
            'username' => $dsn['username'] ?? '',
            'password' => $dsn['password'] ?? '',
            'charset' => 'utf8mb4',
            'options' => [
                \PDO::ATTR_TIMEOUT => 5,
            ],
        ]]);

        try {
            return DB::connection($name)->select($sql, $bindings);
        } finally {
            DB::purge($name);
        }
    }

    /**
     * Reject anything that is not a single read-only statement.
     */
    public function assertReadOnly(string $sql): void
    {
        $trimmed = ltrim($sql);

        if (preg_match('/^(select|show|describe|desc|explain)\b/i', $trimmed) !== 1) {
            throw new InvalidArgumentException('Only read-only queries (SELECT/SHOW/DESCRIBE/EXPLAIN) are permitted.');
        }

        // Disallow stacked statements: a trailing semicolon is fine, an internal one is not.
        if (str_contains(rtrim(rtrim($trimmed), ';'), ';')) {
            throw new InvalidArgumentException('Multiple statements are not permitted.');
        }
    }
}

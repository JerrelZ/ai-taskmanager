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
     * Find rows whose email-like column matches the given address, scanning the
     * external schema for columns named like "%email%". Read-only throughout.
     *
     * @return array<int, array{table: string, column: string, row: array<string, mixed>}>
     */
    public function findByEmail(EmailAccount $account, string $email, int $limit = 5): array
    {
        // Restrict to string columns so boolean/int/date columns whose name merely
        // contains "email" (e.g. weekly_advertiser_emails, sent_email_id,
        // email_verified_at) can't produce false positives via type coercion.
        $columns = $this->select(
            $account,
            'SELECT TABLE_NAME, COLUMN_NAME FROM information_schema.COLUMNS '
                .'WHERE TABLE_SCHEMA = DATABASE() AND COLUMN_NAME LIKE ? '
                ."AND DATA_TYPE IN ('char', 'varchar', 'tinytext', 'text', 'mediumtext', 'longtext') "
                .'LIMIT 25',
            ['%email%'],
        );

        $results = [];

        foreach ($columns as $column) {
            $table = (string) $column->TABLE_NAME;
            $col = (string) $column->COLUMN_NAME;

            // Identifiers can't be bound; they come from information_schema, not user input.
            if (! preg_match('/^[A-Za-z0-9_]+$/', $table) || ! preg_match('/^[A-Za-z0-9_]+$/', $col)) {
                continue;
            }

            $rows = $this->select(
                $account,
                "SELECT * FROM `{$table}` WHERE `{$col}` = ? LIMIT 1",
                [$email],
            );

            if ($rows !== []) {
                $results[] = ['table' => $table, 'column' => $col, 'row' => (array) $rows[0]];
            }

            if (count($results) >= $limit) {
                break;
            }
        }

        return $results;
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

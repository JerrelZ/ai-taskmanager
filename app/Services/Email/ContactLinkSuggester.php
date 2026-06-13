<?php

namespace App\Services\Email;

use App\Models\EmailAccount;
use App\Models\EmailContactLink;

/**
 * Suggests external-database rows that could be linked to an email sender, and
 * resolves an existing link back to its row. All access is read-only and goes
 * through {@see ExternalProjectDb}. Suggestions span any table that has an
 * email-like column, so a sender may be linked to customers, users, companies,
 * or whatever the project's schema offers.
 */
class ContactLinkSuggester
{
    /** Columns we prefer, in order, when building a human-readable label. */
    private const LABEL_COLUMNS = ['name', 'full_name', 'company_name', 'company', 'display_name', 'title', 'email'];

    public function __construct(private readonly ExternalProjectDb $externalDb) {}

    /**
     * Candidate rows across the schema that match the given email address.
     *
     * @return array<int, array{table: string, id_column: string, id: string, label: string, preview: string}>
     */
    public function suggest(EmailAccount $account, string $email): array
    {
        if (blank($account->external_db_dsn) || blank($email)) {
            return [];
        }

        $suggestions = [];

        foreach ($this->externalDb->findByEmail($account, $email) as $match) {
            $table = $match['table'];
            $row = $match['row'];

            $idColumn = $this->identifierColumn($account, $table, $row);

            if ($idColumn === null || ! array_key_exists($idColumn, $row)) {
                continue;
            }

            $suggestions[] = [
                'table' => $table,
                'id_column' => $idColumn,
                'id' => (string) $row[$idColumn],
                'label' => $this->labelFor($row, $email),
                'preview' => $this->preview($row),
            ];
        }

        return $suggestions;
    }

    /**
     * Re-fetch the linked row for display. Returns null if the row no longer exists.
     *
     * @return array{label: string, fields: array<string, mixed>}|null
     */
    public function resolve(EmailContactLink $link): ?array
    {
        $account = $link->account;

        if ($account === null || blank($account->external_db_dsn)) {
            return null;
        }

        if (! $this->isIdentifier($link->external_table) || ! $this->isIdentifier($link->external_id_column)) {
            return null;
        }

        $rows = $this->externalDb->select(
            $account,
            "SELECT * FROM `{$link->external_table}` WHERE `{$link->external_id_column}` = ? LIMIT 1",
            [$link->external_id],
        );

        if ($rows === []) {
            return null;
        }

        $fields = (array) $rows[0];

        return [
            'label' => $link->label ?: $this->labelFor($fields, $link->email),
            'fields' => $fields,
        ];
    }

    /**
     * Pick a stable identifier column for a row: the table's primary key when
     * available, else a present "id" column.
     *
     * @param  array<string, mixed>  $row
     */
    private function identifierColumn(EmailAccount $account, string $table, array $row): ?string
    {
        if (! $this->isIdentifier($table)) {
            return null;
        }

        try {
            $primary = $this->externalDb->select(
                $account,
                'SELECT COLUMN_NAME FROM information_schema.COLUMNS '
                    .'WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_KEY = ? LIMIT 1',
                [$table, 'PRI'],
            );
        } catch (\Throwable) {
            $primary = [];
        }

        $column = $primary !== [] ? (string) $primary[0]->COLUMN_NAME : null;

        if ($column !== null && array_key_exists($column, $row)) {
            return $column;
        }

        return array_key_exists('id', $row) ? 'id' : null;
    }

    /**
     * @param  array<string, mixed>  $row
     */
    private function labelFor(array $row, string $fallback): string
    {
        $lower = array_change_key_case($row, CASE_LOWER);

        foreach (self::LABEL_COLUMNS as $candidate) {
            if (filled($lower[$candidate] ?? null)) {
                return (string) $lower[$candidate];
            }
        }

        // first_name + last_name as a pair.
        $first = $lower['first_name'] ?? $lower['firstname'] ?? null;
        $last = $lower['last_name'] ?? $lower['lastname'] ?? null;

        if (filled($first) || filled($last)) {
            return trim("{$first} {$last}");
        }

        return $fallback;
    }

    /**
     * @param  array<string, mixed>  $row
     */
    private function preview(array $row): string
    {
        return collect($row)
            ->take(6)
            ->map(fn ($value, $key): string => "{$key}={$value}")
            ->implode(', ');
    }

    private function isIdentifier(string $value): bool
    {
        return preg_match('/^[A-Za-z0-9_]+$/', $value) === 1;
    }
}

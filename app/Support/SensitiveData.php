<?php

namespace App\Support;

/**
 * Redacts credential-like values from support-API records before they are ever
 * rendered in the inbox context panel or in contact-link previews. The API is
 * expected to publish only safe fields, but a project could widen a response at
 * any time — this is the belt to that braces, so a token or hash can never
 * reach the screen or a ticket.
 */
class SensitiveData
{
    /**
     * Case-insensitive substrings of a column name that mark its value secret.
     *
     * @var array<int, string>
     */
    private const SENSITIVE_PATTERNS = [
        'password', 'passwd', 'pwd', 'secret', 'token', 'api_key', 'apikey',
        'private_key', 'access_key', 'salt', 'hash', 'otp', 'mfa', 'two_factor',
        'credential',
    ];

    private const REDACTED = '[verborgen]';

    /**
     * Mask values of credential-like columns, keeping the key visible so it is
     * clear the field exists but its value is withheld.
     *
     * @param  array<string, mixed>  $row
     * @return array<string, mixed>
     */
    public static function redactRow(array $row): array
    {
        $redacted = [];

        foreach ($row as $key => $value) {
            $redacted[$key] = self::isSensitive((string) $key) ? self::REDACTED : $value;
        }

        return $redacted;
    }

    public static function isSensitive(string $column): bool
    {
        $needle = mb_strtolower($column);

        foreach (self::SENSITIVE_PATTERNS as $pattern) {
            if (str_contains($needle, $pattern)) {
                return true;
            }
        }

        return false;
    }
}

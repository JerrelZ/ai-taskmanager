<?php

namespace App\Services\Email;

use App\Models\EmailAccount;
use App\Models\EmailContactLink;
use App\Support\SensitiveData;

/**
 * Suggests support-API records that could be linked to an email sender, and
 * resolves an existing link back to its record. All access is read-only and
 * goes through {@see ExternalProjectApi}, so only what the project deliberately
 * publishes is ever seen — never a raw database row.
 */
class ContactLinkSuggester
{
    /** Columns we prefer, in order, when building a human-readable label. */
    private const LABEL_COLUMNS = ['company_name', 'name', 'full_name', 'display_name', 'title', 'email'];

    public function __construct(private readonly ExternalProjectApi $api) {}

    /**
     * Candidate records on the support API that match the given email address.
     *
     * @return array<int, array{resource: string, id: string, label: string, preview: string}>
     */
    public function suggest(EmailAccount $account, string $email): array
    {
        if (! $this->api->configured($account) || blank($email)) {
            return [];
        }

        $suggestions = [];

        foreach ($this->api->lookupUserByEmail($account, $email) as $user) {
            if (! is_array($user) || blank($user['id'] ?? null)) {
                continue;
            }

            $fields = $this->flatten($user);

            $suggestions[] = [
                'resource' => EmailContactLink::RESOURCE_USERS,
                'id' => (string) $user['id'],
                'label' => $this->labelFor($fields, $email),
                'preview' => $this->preview($fields),
            ];
        }

        return $suggestions;
    }

    /**
     * Re-fetch the linked record for display. Returns null when the support API
     * is gone, the resource is unknown, or the record no longer exists.
     *
     * @return array{label: string, fields: array<string, mixed>}|null
     */
    public function resolve(EmailContactLink $link): ?array
    {
        $account = $link->account;

        if ($account === null || ! $this->api->configured($account)) {
            return null;
        }

        if ($link->external_resource !== EmailContactLink::RESOURCE_USERS) {
            return null;
        }

        try {
            $response = $this->api->userSummary($account, $link->external_id);
        } catch (\Throwable) {
            return null;
        }

        $data = $response['data'] ?? $response;

        if (! is_array($data) || $data === []) {
            return null;
        }

        $fields = $this->flatten($data);

        return [
            'label' => $link->label ?: $this->labelFor($fields, $link->email),
            'fields' => SensitiveData::redactRow($fields),
        ];
    }

    /**
     * Flatten a nested API payload to scalar values with dotted keys, so it can
     * be rendered as a flat key/value list (e.g. `this_month.revenue_excl_vat`).
     *
     * @param  array<string, mixed>  $data
     * @return array<string, scalar|null>
     */
    private function flatten(array $data, string $prefix = ''): array
    {
        $flat = [];

        foreach ($data as $key => $value) {
            $name = $prefix === '' ? (string) $key : "{$prefix}.{$key}";

            if (is_array($value)) {
                $flat = [...$flat, ...$this->flatten($value, $name)];

                continue;
            }

            $flat[$name] = is_bool($value) ? ($value ? 'ja' : 'nee') : $value;
        }

        return $flat;
    }

    /**
     * @param  array<string, mixed>  $fields
     */
    private function labelFor(array $fields, string $fallback): string
    {
        $lower = array_change_key_case($fields, CASE_LOWER);

        foreach (self::LABEL_COLUMNS as $candidate) {
            if (filled($lower[$candidate] ?? null)) {
                return (string) $lower[$candidate];
            }
        }

        return $fallback;
    }

    /**
     * @param  array<string, mixed>  $fields
     */
    private function preview(array $fields): string
    {
        return collect(SensitiveData::redactRow($fields))
            ->take(6)
            ->map(fn ($value, $key): string => "{$key}={$value}")
            ->implode(', ');
    }
}

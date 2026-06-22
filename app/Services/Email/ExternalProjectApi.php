<?php

namespace App\Services\Email;

use App\Models\EmailAccount;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Thin read-only client for a project's external support API (e.g. Revboost's
 * https://www.revboost.nl/api/internal/v1). The full base URL — including any
 * path prefix — is configured per account, so endpoints are requested relative
 * to it (e.g. "users"). Endpoints return business-computed data — stable
 * contracts that survive schema changes — which the MCP tools and context
 * builder prefer over raw database queries when an API is configured.
 */
class ExternalProjectApi
{
    public function configured(EmailAccount $account): bool
    {
        return filled($account->external_api_base_url) && filled($account->external_api_token);
    }

    /**
     * Look up a user by email address.
     *
     * @return array<int, array<string, mixed>>
     */
    public function lookupUserByEmail(EmailAccount $account, string $email): array
    {
        $data = $this->get($account, 'users', ['email' => $email]);

        return $data['data'] ?? $data['users'] ?? (array_is_list($data) ? $data : []);
    }

    /**
     * @return array<string, mixed>
     */
    public function userSummary(EmailAccount $account, string $userId): array
    {
        return $this->get($account, "users/{$userId}/summary");
    }

    /**
     * @return array<string, mixed>
     */
    public function revenue(EmailAccount $account, string $userId, string $from, string $to, string $granularity = 'day'): array
    {
        return $this->get($account, "users/{$userId}/revenue", [
            'from' => $from,
            'to' => $to,
            'granularity' => $granularity,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    public function invoices(EmailAccount $account, string $userId, ?string $status = null): array
    {
        return $this->get($account, "users/{$userId}/invoices", array_filter(['status' => $status]));
    }

    /**
     * @param  array<string, mixed>  $query
     * @return array<string, mixed>
     */
    private function get(EmailAccount $account, string $path, array $query = []): array
    {
        if (! $this->configured($account)) {
            throw new RuntimeException('Voor dit project is geen support-API geconfigureerd.');
        }

        $response = $this->client($account)->get($path, $query);

        if (! $response->successful()) {
            throw new RuntimeException("API gaf status {$response->status()} terug voor /{$path}.");
        }

        return (array) $response->json();
    }

    private function client(EmailAccount $account): PendingRequest
    {
        return Http::baseUrl(rtrim((string) $account->external_api_base_url, '/'))
            ->withToken((string) $account->external_api_token)
            ->acceptJson()
            ->timeout(20);
    }
}

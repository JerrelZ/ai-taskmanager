<?php

namespace App\Http\Controllers\Webhooks;

use App\Http\Controllers\Controller;
use App\Jobs\Email\IngestResendInboundEmail;
use App\Services\Email\ResendWebhookVerifier;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Receives Resend inbound `email.received` webhooks. The webhook only carries
 * metadata, so we authenticate it, then hand the email id off to a job that
 * pulls the full message and feeds the normal parse pipeline.
 */
class ResendInboundController extends Controller
{
    public function __construct(private readonly ResendWebhookVerifier $verifier) {}

    public function __invoke(Request $request): JsonResponse
    {
        if (! $this->verifier->verify($request)) {
            return response()->json(['message' => 'Invalid signature.'], 401);
        }

        // Acknowledge every other event type (delivered, bounced, …) so Resend
        // doesn't retry deliveries we simply don't act on.
        if ($request->input('type') !== 'email.received') {
            return response()->json(['message' => 'Ignored.'], 200);
        }

        $emailId = $request->input('data.email_id');

        if (! is_string($emailId) || $emailId === '') {
            return response()->json(['message' => 'Missing email id.'], 422);
        }

        $recipients = $this->recipients($request->all());

        IngestResendInboundEmail::dispatch($emailId, $recipients);

        return response()->json(['message' => 'Accepted.'], 202);
    }

    /**
     * Every address the email was delivered to (to + cc + bcc), used to find the
     * receiving account.
     *
     * @param  array<string, mixed>  $payload
     * @return list<string>
     */
    private function recipients(array $payload): array
    {
        $addresses = array_merge(
            (array) data_get($payload, 'data.to', []),
            (array) data_get($payload, 'data.cc', []),
            (array) data_get($payload, 'data.bcc', []),
        );

        return array_values(array_unique(array_filter(
            array_map(fn ($address) => is_string($address) ? trim($address) : '', $addresses),
        )));
    }
}

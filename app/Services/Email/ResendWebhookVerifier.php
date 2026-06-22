<?php

namespace App\Services\Email;

use Illuminate\Http\Request;

/**
 * Verifies Resend inbound webhook signatures. Resend signs webhooks with Svix:
 * the signed content is "{id}.{timestamp}.{body}", HMAC-SHA256'd with the
 * (base64) secret behind the `whsec_` prefix, and the result base64-encoded.
 *
 * @see https://resend.com/docs/dashboard/webhooks/verify-webhooks-requests
 */
class ResendWebhookVerifier
{
    /**
     * Reject deliveries whose timestamp is too far from now, to blunt replay.
     */
    private const TOLERANCE_SECONDS = 300;

    public function __construct(private readonly ?string $secret) {}

    /**
     * Whether the request carries a valid, fresh Svix signature.
     */
    public function verify(Request $request): bool
    {
        if ($this->secret === null || $this->secret === '') {
            return false;
        }

        $id = $request->header('svix-id');
        $timestamp = $request->header('svix-timestamp');
        $signatureHeader = $request->header('svix-signature');

        if ($id === null || $timestamp === null || $signatureHeader === null) {
            return false;
        }

        if (! $this->timestampIsFresh($timestamp)) {
            return false;
        }

        $key = $this->secretKey();

        if ($key === null) {
            return false;
        }

        $signedContent = "{$id}.{$timestamp}.{$request->getContent()}";
        $expected = base64_encode(hash_hmac('sha256', $signedContent, $key, true));

        // The header is a space-separated list of "version,signature" pairs; any
        // matching v1 signature is enough. Compare in constant time.
        foreach (explode(' ', $signatureHeader) as $pair) {
            [$version, $signature] = array_pad(explode(',', $pair, 2), 2, '');

            if ($version === 'v1' && hash_equals($expected, $signature)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Decode the raw HMAC key from the `whsec_`-prefixed secret.
     */
    private function secretKey(): ?string
    {
        $secret = str_starts_with($this->secret, 'whsec_')
            ? substr($this->secret, 6)
            : $this->secret;

        $key = base64_decode($secret, true);

        return $key === false ? null : $key;
    }

    private function timestampIsFresh(string $timestamp): bool
    {
        if (! ctype_digit($timestamp)) {
            return false;
        }

        return abs(now()->getTimestamp() - (int) $timestamp) <= self::TOLERANCE_SECONDS;
    }
}

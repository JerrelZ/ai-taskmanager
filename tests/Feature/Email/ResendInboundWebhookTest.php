<?php

use App\Jobs\Email\IngestResendInboundEmail;
use App\Jobs\Email\ParseEmailMessage;
use App\Models\EmailAccount;
use App\Models\EmailMessage;
use App\Services\Email\RawEmailStore;
use App\Services\Email\ResendWebhookVerifier;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;

const RESEND_TEST_SECRET = 'whsec_dGVzdC1zZWNyZXQtMTIzNDU2Nzg5MA==';

/**
 * Sign a raw JSON body the way Resend (Svix) does, returning the request headers.
 *
 * @return array<string, string>
 */
function svixHeaders(string $payload, ?int $timestamp = null, string $id = 'msg_2abc'): array
{
    $timestamp ??= now()->getTimestamp();
    $key = base64_decode(substr(RESEND_TEST_SECRET, 6));
    $signature = base64_encode(hash_hmac('sha256', "{$id}.{$timestamp}.{$payload}", $key, true));

    return [
        'HTTP_SVIX_ID' => $id,
        'HTTP_SVIX_TIMESTAMP' => (string) $timestamp,
        'HTTP_SVIX_SIGNATURE' => "v1,{$signature}",
        'CONTENT_TYPE' => 'application/json',
        'HTTP_ACCEPT' => 'application/json',
    ];
}

/**
 * @param  array<string, mixed>  $data
 */
function receivedPayload(array $data = []): string
{
    return json_encode([
        'type' => 'email.received',
        'created_at' => '2026-06-22T10:00:00.000Z',
        'data' => array_merge([
            'email_id' => 'eml_123',
            'from' => 'klant@voorbeeld.nl',
            'to' => ['support@mijnbedrijf.nl'],
            'cc' => [],
            'bcc' => [],
            'message_id' => '<abc@voorbeeld.nl>',
            'subject' => 'Vraag over mijn bestelling',
            'attachments' => [],
        ], $data),
    ], JSON_THROW_ON_ERROR);
}

function postWebhook(string $payload, array $headers): TestResponse
{
    return test()->call('POST', route('webhooks.resend.inbound'), [], [], [], $headers, $payload);
}

beforeEach(function () {
    config(['services.resend.webhook_secret' => RESEND_TEST_SECRET, 'services.resend.key' => 're_test']);
    app()->forgetInstance(ResendWebhookVerifier::class);
    Storage::fake('local');
});

it('rejects a webhook without a valid signature', function () {
    Bus::fake([IngestResendInboundEmail::class]);

    $payload = receivedPayload();

    postWebhook($payload, [
        'HTTP_SVIX_ID' => 'msg_2abc',
        'HTTP_SVIX_TIMESTAMP' => (string) now()->getTimestamp(),
        'HTTP_SVIX_SIGNATURE' => 'v1,deadbeef',
        'CONTENT_TYPE' => 'application/json',
    ])->assertStatus(401);

    Bus::assertNotDispatched(IngestResendInboundEmail::class);
});

it('rejects a tampered body whose signature no longer matches', function () {
    Bus::fake([IngestResendInboundEmail::class]);

    $headers = svixHeaders(receivedPayload());

    // Same headers, different body.
    postWebhook(receivedPayload(['subject' => 'Iets anders']), $headers)->assertStatus(401);

    Bus::assertNotDispatched(IngestResendInboundEmail::class);
});

it('accepts a signed email.received webhook and dispatches ingestion', function () {
    Bus::fake([IngestResendInboundEmail::class]);

    $payload = receivedPayload();

    postWebhook($payload, svixHeaders($payload))->assertStatus(202);

    Bus::assertDispatched(IngestResendInboundEmail::class, function (IngestResendInboundEmail $job) {
        return $job->resendEmailId === 'eml_123'
            && in_array('support@mijnbedrijf.nl', $job->recipients, true);
    });
});

it('acknowledges other event types without ingesting', function () {
    Bus::fake([IngestResendInboundEmail::class]);

    $payload = json_encode(['type' => 'email.delivered', 'data' => ['email_id' => 'eml_x']], JSON_THROW_ON_ERROR);

    postWebhook($payload, svixHeaders($payload))->assertStatus(200);

    Bus::assertNotDispatched(IngestResendInboundEmail::class);
});

it('ingests an inbound email into the matching account and feeds the parse pipeline', function () {
    Bus::fake([ParseEmailMessage::class]);

    $account = EmailAccount::factory()->create(['email_address' => 'support@mijnbedrijf.nl', 'is_active' => true]);

    $raw = "Message-ID: <abc@voorbeeld.nl>\r\nFrom: Klant <klant@voorbeeld.nl>\r\nTo: support@mijnbedrijf.nl\r\nSubject: Vraag\r\n\r\nHallo, een vraag.";

    Http::fake([
        'api.resend.com/emails/receiving/*' => Http::response([
            'message_id' => '<abc@voorbeeld.nl>',
            'raw' => ['download_url' => 'https://dl.resend.com/raw/eml_123'],
        ], 200),
        'dl.resend.com/*' => Http::response($raw, 200),
    ]);

    (new IngestResendInboundEmail('eml_123', ['support@mijnbedrijf.nl']))->handle(app(RawEmailStore::class));

    $message = EmailMessage::where('provider_email_id', 'eml_123')->first();

    expect($message)->not->toBeNull()
        ->and($message->provider)->toBe(EmailMessage::PROVIDER_RESEND)
        ->and($message->email_account_id)->toBe($account->id)
        ->and($message->direction)->toBe(EmailMessage::DIRECTION_INBOUND)
        ->and($message->status)->toBe(EmailMessage::STATUS_RECEIVED)
        ->and($message->uid)->toBeNull();

    Storage::disk('local')->assertExists($message->raw_path);
    Bus::assertDispatchedTimes(ParseEmailMessage::class, 1);
});

it('is idempotent: a redelivered webhook does not create a duplicate', function () {
    Bus::fake([ParseEmailMessage::class]);

    EmailAccount::factory()->create(['email_address' => 'support@mijnbedrijf.nl', 'is_active' => true]);

    Http::fake([
        'api.resend.com/emails/receiving/*' => Http::response([
            'message_id' => '<abc@voorbeeld.nl>',
            'raw' => ['download_url' => 'https://dl.resend.com/raw/eml_123'],
        ], 200),
        'dl.resend.com/*' => Http::response('Message-ID: <abc@voorbeeld.nl>', 200),
    ]);

    foreach (range(1, 2) as $ignored) {
        (new IngestResendInboundEmail('eml_123', ['support@mijnbedrijf.nl']))->handle(app(RawEmailStore::class));
    }

    expect(EmailMessage::where('provider_email_id', 'eml_123')->count())->toBe(1);
    Bus::assertDispatchedTimes(ParseEmailMessage::class, 1);
});

it('drops an email addressed to an unknown account', function () {
    Bus::fake([ParseEmailMessage::class]);

    EmailAccount::factory()->create(['email_address' => 'support@mijnbedrijf.nl', 'is_active' => true]);

    Http::fake(); // No external calls should be needed.

    (new IngestResendInboundEmail('eml_999', ['niemand@onbekend.nl']))->handle(app(RawEmailStore::class));

    expect(EmailMessage::count())->toBe(0);
    Bus::assertNotDispatched(ParseEmailMessage::class);
    Http::assertNothingSent();
});

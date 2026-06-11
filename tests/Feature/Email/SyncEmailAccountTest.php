<?php

use App\Jobs\Email\ParseEmailMessage;
use App\Jobs\Email\SyncEmailAccount;
use App\Models\EmailAccount;
use App\Models\EmailFolder;
use App\Models\EmailMessage;
use App\Services\Email\ImapClientFactory;
use App\Services\Email\RawEmailStore;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Storage;
use Tests\Support\Email\FakeImapClientFactory;

function rawEmail(string $messageId, string $subject = 'Hello', string $from = 'sender@example.com'): string
{
    return implode("\r\n", [
        "Message-ID: {$messageId}",
        "From: Sender <{$from}>",
        'To: me@example.com',
        "Subject: {$subject}",
        'Date: Wed, 11 Jun 2026 10:00:00 +0000',
        '',
        'Body of the message.',
    ]);
}

function runSync(EmailAccount $account): void
{
    $job = new SyncEmailAccount($account->id);
    $job->handle(app(ImapClientFactory::class), app(RawEmailStore::class));
}

beforeEach(function () {
    Storage::fake('local');
    $this->fakeImap = new FakeImapClientFactory;
    $this->app->instance(ImapClientFactory::class, $this->fakeImap);
});

it('persists the raw source first and marks every message received', function () {
    Bus::fake([ParseEmailMessage::class]);

    $account = EmailAccount::factory()->create();
    $this->fakeImap->for($account)
        ->seed('INBOX', 1, rawEmail('<a@example.com>'))
        ->seed('INBOX', 2, rawEmail('<b@example.com>'));

    runSync($account);

    $messages = EmailMessage::where('email_account_id', $account->id)->get();
    expect($messages)->toHaveCount(2);

    foreach ($messages as $message) {
        expect($message->status)->toBe(EmailMessage::STATUS_RECEIVED);
        expect($message->direction)->toBe(EmailMessage::DIRECTION_INBOUND);
        Storage::disk('local')->assertExists($message->raw_path);
    }

    $folder = EmailFolder::where('email_account_id', $account->id)->where('name', 'INBOX')->first();
    expect($folder->last_seen_uid)->toBe(2);

    Bus::assertDispatchedTimes(ParseEmailMessage::class, 2);
});

it('is idempotent across repeated syncs', function () {
    Bus::fake([ParseEmailMessage::class]);

    $account = EmailAccount::factory()->create();
    $this->fakeImap->for($account)
        ->seed('INBOX', 1, rawEmail('<a@example.com>'))
        ->seed('INBOX', 2, rawEmail('<b@example.com>'));

    runSync($account);
    runSync($account);

    expect(EmailMessage::where('email_account_id', $account->id)->count())->toBe(2);

    $folder = EmailFolder::where('email_account_id', $account->id)->where('name', 'INBOX')->first();
    expect($folder->last_seen_uid)->toBe(2);

    // Parse only dispatched for the two genuinely new messages, not on the re-run.
    Bus::assertDispatchedTimes(ParseEmailMessage::class, 2);
});

it('ingests newly arrived messages without re-ingesting old ones', function () {
    Bus::fake([ParseEmailMessage::class]);

    $account = EmailAccount::factory()->create();
    $connection = $this->fakeImap->for($account)
        ->seed('INBOX', 1, rawEmail('<a@example.com>'))
        ->seed('INBOX', 2, rawEmail('<b@example.com>'));

    runSync($account);

    $connection->seed('INBOX', 3, rawEmail('<c@example.com>'));
    runSync($account);

    expect(EmailMessage::where('email_account_id', $account->id)->count())->toBe(3);
    Bus::assertDispatchedTimes(ParseEmailMessage::class, 3);
});

it('never loses email when a sync crashes mid-folder', function () {
    Bus::fake([ParseEmailMessage::class]);

    $account = EmailAccount::factory()->create();
    $connection = $this->fakeImap->for($account)
        ->seed('INBOX', 1, rawEmail('<a@example.com>'))
        ->seed('INBOX', 2, rawEmail('<b@example.com>'))
        ->seed('INBOX', 3, rawEmail('<c@example.com>'));

    // Crash while fetching the 3rd message.
    $connection->failOnUid = 3;

    expect(fn () => runSync($account))->toThrow(RuntimeException::class);

    // The first two committed; the watermark stopped at the last durable UID.
    expect(EmailMessage::where('email_account_id', $account->id)->count())->toBe(2);
    $folder = EmailFolder::where('email_account_id', $account->id)->where('name', 'INBOX')->first();
    expect($folder->last_seen_uid)->toBe(2);

    // Re-run after the transient failure clears: the 3rd is picked up, no duplicates.
    runSync($account);

    expect(EmailMessage::where('email_account_id', $account->id)->count())->toBe(3);
    expect(EmailMessage::where('email_account_id', $account->id)->where('uid', 3)->exists())->toBeTrue();
    $folder->refresh();
    expect($folder->last_seen_uid)->toBe(3);
});

it('handles a UIDVALIDITY change with a full resync and no duplicates', function () {
    Bus::fake([ParseEmailMessage::class]);

    $account = EmailAccount::factory()->create();
    $connection = $this->fakeImap->for($account)
        ->seed('INBOX', 1, rawEmail('<a@example.com>'), uidValidity: 100)
        ->seed('INBOX', 2, rawEmail('<b@example.com>'), uidValidity: 100);

    runSync($account);
    expect(EmailMessage::where('email_account_id', $account->id)->count())->toBe(2);

    // Mailbox is recreated: UIDVALIDITY changes and UID 1 is reused for a NEW message.
    $connection->folders['INBOX']['messages'] = [];
    $connection->setUidValidity('INBOX', 200)
        ->seed('INBOX', 1, rawEmail('<c@example.com>'), uidValidity: 200);

    runSync($account);

    // Old epoch rows are kept; the reused UID under the new epoch is a distinct row.
    expect(EmailMessage::where('email_account_id', $account->id)->count())->toBe(3);
    expect(EmailMessage::where('email_account_id', $account->id)->where('uid_validity', 200)->where('uid', 1)->exists())->toBeTrue();

    $folder = EmailFolder::where('email_account_id', $account->id)->where('name', 'INBOX')->first();
    expect((int) $folder->uid_validity)->toBe(200);
    expect($folder->last_seen_uid)->toBe(1);
});

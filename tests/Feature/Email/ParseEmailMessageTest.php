<?php

use App\Jobs\Email\CategoriseEmailThread;
use App\Jobs\Email\ParseEmailMessage;
use App\Models\EmailAccount;
use App\Models\EmailFolder;
use App\Models\EmailMessage;
use App\Models\EmailThread;
use App\Services\AttachmentService;
use App\Services\Email\MailParser;
use App\Services\Email\RawEmailStore;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Storage;

/**
 * @param  array<string, string>  $headers
 */
function mimeMessage(string $messageId, string $subject = 'Hello there', array $headers = [], string $body = 'Hi, this is the body.'): string
{
    $lines = [
        "Message-ID: {$messageId}",
        'From: Alice Sender <alice@example.com>',
        'To: support@example.com',
        "Subject: {$subject}",
        'Date: Wed, 11 Jun 2026 10:00:00 +0000',
    ];

    foreach ($headers as $name => $value) {
        $lines[] = "{$name}: {$value}";
    }

    return implode("\r\n", [...$lines, '', $body]);
}

function receivedMessage(EmailAccount $account, EmailFolder $folder, string $raw, int $uid, ?string $messageId = null): EmailMessage
{
    $path = app(RawEmailStore::class)->store($account->id, $folder->name, 1, $uid, $raw);

    return EmailMessage::factory()->create([
        'email_account_id' => $account->id,
        'email_folder_id' => $folder->id,
        'email_thread_id' => null,
        'uid_validity' => 1,
        'uid' => $uid,
        'message_id' => $messageId,
        'raw_path' => $path,
        'status' => EmailMessage::STATUS_RECEIVED,
        'subject' => null,
        'text_body' => null,
        'from_email' => null,
        'received_at' => now(),
        'sent_at' => null,
    ]);
}

function parse(EmailMessage $message): void
{
    (new ParseEmailMessage($message->id))->handle(
        app(RawEmailStore::class),
        app(MailParser::class),
        app(AttachmentService::class),
    );
}

beforeEach(function () {
    Storage::fake('local');
    $this->account = EmailAccount::factory()->create();
    $this->folder = EmailFolder::factory()->create(['email_account_id' => $this->account->id, 'name' => 'INBOX']);
});

it('parses MIME fields, builds a thread and queues categorisation', function () {
    Bus::fake([CategoriseEmailThread::class]);

    $message = receivedMessage($this->account, $this->folder, mimeMessage('<root@example.com>'), 1, '<root@example.com>');

    parse($message);

    $message->refresh();
    expect($message->status)->toBe(EmailMessage::STATUS_PARSED);
    expect($message->from_email)->toBe('alice@example.com');
    expect($message->from_name)->toBe('Alice Sender');
    expect($message->subject)->toBe('Hello there');
    expect($message->text_body)->toContain('this is the body');
    expect($message->email_thread_id)->not->toBeNull();

    $thread = EmailThread::find($message->email_thread_id);
    expect($thread->project_id)->toBe($this->account->project_id);
    expect($thread->message_count)->toBe(1);

    Bus::assertDispatched(CategoriseEmailThread::class, fn (CategoriseEmailThread $job): bool => $job->emailThreadId === $thread->id);
});

it('groups a reply into the same thread as the message it answers', function () {
    Bus::fake([CategoriseEmailThread::class]);

    $root = receivedMessage($this->account, $this->folder, mimeMessage('<root@example.com>', 'Question'), 1, '<root@example.com>');
    $reply = receivedMessage(
        $this->account,
        $this->folder,
        mimeMessage('<reply@example.com>', 'Re: Question', [
            'In-Reply-To' => '<root@example.com>',
            'References' => '<root@example.com>',
        ]),
        2,
        '<reply@example.com>',
    );

    parse($root);
    parse($reply);

    expect($root->refresh()->email_thread_id)->toBe($reply->refresh()->email_thread_id);
    expect(EmailThread::count())->toBe(1);

    $thread = EmailThread::first();
    expect($thread->message_count)->toBe(2);
    expect($thread->subject)->toBe('Question');
});

it('falls back to a normalised-subject thread key without message ids', function () {
    Bus::fake([CategoriseEmailThread::class]);

    $a = receivedMessage($this->account, $this->folder, mimeMessage('<a@example.com>', 'Invoice 42'), 1, null);
    $b = receivedMessage($this->account, $this->folder, mimeMessage('<b@example.com>', 'Re: Invoice 42'), 2, null);

    // Strip Message-ID so threading must rely on the normalised subject.
    Storage::disk('local')->put($a->raw_path, str_replace("Message-ID: <a@example.com>\r\n", '', mimeMessage('<a@example.com>', 'Invoice 42')));
    Storage::disk('local')->put($b->raw_path, str_replace("Message-ID: <b@example.com>\r\n", '', mimeMessage('<b@example.com>', 'Re: Invoice 42')));

    parse($a);
    parse($b);

    expect(EmailThread::count())->toBe(1);
    expect(EmailThread::first()->subject)->toBe('Invoice 42');
});

it('isolates a parse failure and marks it failed after max attempts without losing the raw', function () {
    Bus::fake([CategoriseEmailThread::class]);

    $good = receivedMessage($this->account, $this->folder, mimeMessage('<good@example.com>'), 1, '<good@example.com>');
    $bad = receivedMessage($this->account, $this->folder, mimeMessage('<bad@example.com>'), 2, '<bad@example.com>');

    // Corrupt the bad message's raw location so parsing always throws.
    $bad->forceFill(['raw_path' => 'email/raw/missing.eml'])->save();

    parse($good);
    expect($good->refresh()->status)->toBe(EmailMessage::STATUS_PARSED);

    for ($attempt = 0; $attempt < EmailMessage::MAX_PARSE_ATTEMPTS; $attempt++) {
        try {
            parse($bad);
        } catch (Throwable) {
            // Expected: the job rethrows so the queue would retry.
        }
    }

    $bad->refresh();
    expect($bad->status)->toBe(EmailMessage::STATUS_PARSE_FAILED);
    expect($bad->parse_attempts)->toBe(EmailMessage::MAX_PARSE_ATTEMPTS);
    // The original raw row still exists — nothing was lost.
    expect(EmailMessage::whereKey($bad->id)->exists())->toBeTrue();
});

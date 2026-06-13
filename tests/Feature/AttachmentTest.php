<?php

use App\Enums\UserRole;
use App\Jobs\Email\ParseEmailMessage;
use App\Livewire\Messages\Index as MessagesIndex;
use App\Livewire\Tasks\TaskDetail;
use App\Models\Attachment;
use App\Models\Client;
use App\Models\Conversation;
use App\Models\EmailAccount;
use App\Models\EmailFolder;
use App\Models\EmailMessage;
use App\Models\Task;
use App\Models\User;
use App\Services\AttachmentService;
use App\Services\Email\MailParser;
use App\Services\Email\RawEmailStore;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

beforeEach(function () {
    Storage::fake('local');
    $this->user = User::factory()->create();
    $this->actingAs($this->user);
});

it('stores an upload and links it to the attachable', function () {
    $task = Task::factory()->create();
    $file = UploadedFile::fake()->create('rapport.pdf', 12, 'application/pdf');

    $attachment = app(AttachmentService::class)->storeUpload($file, $task, $this->user);

    expect($attachment->attachable_id)->toBe($task->id);
    expect($attachment->attachable_type)->toBe($task->getMorphClass());
    expect($attachment->filename)->toBe('rapport.pdf');
    expect($attachment->uploaded_by)->toBe($this->user->id);
    Storage::disk('local')->assertExists($attachment->path);
});

it('deletes the underlying file when the attachment row is deleted', function () {
    $task = Task::factory()->create();
    $attachment = app(AttachmentService::class)->storeUpload(UploadedFile::fake()->create('x.pdf', 4), $task);

    $path = $attachment->path;
    $attachment->delete();

    Storage::disk('local')->assertMissing($path);
});

it('extracts and stores email attachments during parsing', function () {
    $account = EmailAccount::factory()->create();
    $folder = EmailFolder::factory()->create(['email_account_id' => $account->id, 'name' => 'INBOX']);

    $raw = implode("\r\n", [
        'Message-ID: <att@example.com>',
        'From: Alice <alice@example.com>',
        'To: support@example.com',
        'Subject: Factuur',
        'Date: Wed, 11 Jun 2026 10:00:00 +0000',
        'MIME-Version: 1.0',
        'Content-Type: multipart/mixed; boundary="BOUND"',
        '',
        '--BOUND',
        'Content-Type: text/plain; charset=utf-8',
        '',
        'Zie bijlage.',
        '--BOUND',
        'Content-Type: application/pdf; name="factuur.pdf"',
        'Content-Disposition: attachment; filename="factuur.pdf"',
        'Content-Transfer-Encoding: base64',
        '',
        base64_encode('%PDF-1.4 fake invoice'),
        '--BOUND--',
        '',
    ]);

    $path = app(RawEmailStore::class)->store($account->id, 'INBOX', 1, 1, $raw);
    $message = EmailMessage::factory()->create([
        'email_account_id' => $account->id,
        'email_folder_id' => $folder->id,
        'uid_validity' => 1,
        'uid' => 1,
        'raw_path' => $path,
        'status' => EmailMessage::STATUS_RECEIVED,
    ]);

    (new ParseEmailMessage($message->id))->handle(
        app(RawEmailStore::class),
        app(MailParser::class),
        app(AttachmentService::class),
    );

    $attachments = $message->fresh()->attachments;
    expect($attachments)->toHaveCount(1);
    expect($attachments->first()->filename)->toBe('factuur.pdf');
});

it('allows a team member to download an attachment but denies an unrelated client', function () {
    $task = Task::factory()->create();
    $attachment = app(AttachmentService::class)->storeUpload(UploadedFile::fake()->create('doc.pdf', 4), $task);

    $this->get(route('attachments.download', $attachment))->assertOk();

    // A client from a different client org cannot access it.
    $client = User::factory()->create(['role' => UserRole::Client, 'client_id' => Client::factory()->create()->id]);
    $this->actingAs($client)->get(route('attachments.download', $attachment))->assertForbidden();
});

it('uploads attachments to a ticket via the task panel', function () {
    $task = Task::factory()->create();

    Livewire::test(TaskDetail::class)
        ->call('open', $task->id)
        ->set('newAttachments', [UploadedFile::fake()->create('bijlage.pdf', 6)])
        ->call('uploadAttachments')
        ->assertHasNoErrors();

    expect($task->attachments()->count())->toBe(1);
});

it('shares a file in a chat message', function () {
    $conversation = Conversation::factory()->create();
    $conversation->users()->sync([$this->user->id]);

    Livewire::test(MessagesIndex::class)
        ->call('openConversation', $conversation->id)
        ->set('newChatAttachments', [UploadedFile::fake()->image('foto.png')])
        ->call('send');

    $message = $conversation->messages()->latest()->first();
    expect($message)->not->toBeNull();
    expect($message->attachments)->toHaveCount(1);
    expect(Attachment::count())->toBe(1);
});

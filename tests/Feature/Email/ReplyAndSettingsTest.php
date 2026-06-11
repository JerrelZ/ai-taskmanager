<?php

use App\Jobs\Email\SyncEmailAccount;
use App\Livewire\Email\Inbox;
use App\Mail\EmailReply;
use App\Models\EmailAccount;
use App\Models\EmailFolder;
use App\Models\EmailMessage;
use App\Models\EmailThread;
use App\Models\Project;
use App\Models\User;
use App\Services\Email\ImapClientFactory;
use App\Services\Email\RawEmailStore;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\Support\Email\FakeImapClientFactory;

beforeEach(function () {
    Storage::fake('local');
    $this->fakeImap = new FakeImapClientFactory;
    $this->app->instance(ImapClientFactory::class, $this->fakeImap);

    $this->user = User::factory()->create();
    $this->actingAs($this->user);
    $this->project = Project::factory()->create();
    $this->account = EmailAccount::factory()->create(['project_id' => $this->project->id]);
});

function inboundThread(EmailAccount $account): array
{
    $folder = EmailFolder::firstOrCreate(['email_account_id' => $account->id, 'name' => 'INBOX']);
    $thread = EmailThread::factory()->create([
        'email_account_id' => $account->id,
        'project_id' => $account->project_id,
        'subject' => 'Vraag over levering',
    ]);

    $message = EmailMessage::factory()->create([
        'email_account_id' => $account->id,
        'email_folder_id' => $folder->id,
        'email_thread_id' => $thread->id,
        'direction' => EmailMessage::DIRECTION_INBOUND,
        'from_email' => 'klant@voorbeeld.nl',
        'message_id' => '<klant-1@voorbeeld.nl>',
        'subject' => 'Vraag over levering',
        'status' => EmailMessage::STATUS_CATEGORISED,
    ]);

    return [$thread, $message];
}

it('sends a reply with threading headers and records it as an outbound message', function () {
    Mail::fake();

    [$thread, $inbound] = inboundThread($this->account);

    Livewire::test(Inbox::class, ['project' => $this->project])
        ->call('selectThread', $thread->id)
        ->set('replyBody', 'Bedankt voor uw bericht, de levering is onderweg.')
        ->call('sendReply')
        ->assertSet('replyBody', '');

    Mail::assertSent(EmailReply::class, function (EmailReply $mail): bool {
        return $mail->toAddress === 'klant@voorbeeld.nl'
            && $mail->inReplyTo === 'klant-1@voorbeeld.nl'
            && in_array('klant-1@voorbeeld.nl', $mail->references, true)
            && str_starts_with($mail->subjectLine, 'Re:');
    });

    $outbound = EmailMessage::where('email_account_id', $this->account->id)
        ->where('direction', EmailMessage::DIRECTION_OUTBOUND)
        ->first();

    expect($outbound)->not->toBeNull();
    expect($outbound->email_thread_id)->toBe($thread->id);
    expect($outbound->to)->toContain('klant@voorbeeld.nl');
    expect($outbound->text_body)->toContain('levering is onderweg');
});

it('appends the sent reply to the IMAP Sent folder', function () {
    Mail::fake();

    [$thread] = inboundThread($this->account);

    Livewire::test(Inbox::class, ['project' => $this->project])
        ->call('selectThread', $thread->id)
        ->set('replyBody', 'Een antwoord.')
        ->call('sendReply');

    $appended = $this->fakeImap->for($this->account)->appended;
    expect($appended)->toHaveCount(1);
    expect($appended[0]['folder'])->toBe('Sent');
});

it('does not duplicate the outbound message when the Sent folder is later synced', function () {
    Mail::fake();

    [$thread] = inboundThread($this->account);

    Livewire::test(Inbox::class, ['project' => $this->project])
        ->call('selectThread', $thread->id)
        ->set('replyBody', 'Antwoord dat in Sent belandt.')
        ->call('sendReply');

    // The fake's Sent folder now holds the appended copy with our Message-ID.
    (new SyncEmailAccount($this->account->id))->handle($this->fakeImap, app(RawEmailStore::class));

    expect(EmailMessage::where('email_account_id', $this->account->id)
        ->where('direction', EmailMessage::DIRECTION_OUTBOUND)
        ->count())->toBe(1);
});

it('creates an encrypted email account from the settings form', function () {
    $project = Project::factory()->create();

    Livewire::test(Inbox::class, ['project' => $project])
        ->call('openSettings')
        ->set('emailAddress', 'support@bedrijf.nl')
        ->set('imapHost', 'imap.bedrijf.nl')
        ->set('smtpHost', 'smtp.bedrijf.nl')
        ->set('username', 'support@bedrijf.nl')
        ->set('accountPassword', 'geheim-app-wachtwoord')
        ->call('saveAccount')
        ->assertHasNoErrors();

    $account = EmailAccount::where('project_id', $project->id)->first();
    expect($account)->not->toBeNull();
    expect($account->password)->toBe('geheim-app-wachtwoord');

    $raw = DB::table('email_accounts')->where('id', $account->id)->value('password');
    expect($raw)->not->toContain('geheim-app-wachtwoord');
});

it('keeps the existing password when the field is left blank on edit', function () {
    Livewire::test(Inbox::class, ['project' => $this->project])
        ->call('openSettings')
        ->set('emailAddress', 'nieuw@bedrijf.nl')
        ->set('imapHost', 'imap.bedrijf.nl')
        ->set('smtpHost', 'smtp.bedrijf.nl')
        ->set('username', 'nieuw@bedrijf.nl')
        ->set('accountPassword', '')
        ->call('saveAccount')
        ->assertHasNoErrors();

    $this->account->refresh();
    expect($this->account->email_address)->toBe('nieuw@bedrijf.nl');
    expect($this->account->password)->toBe('app-password'); // unchanged factory default
});

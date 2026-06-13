<?php

use App\Livewire\Email\Inbox;
use App\Models\EmailAccount;
use App\Models\EmailFolder;
use App\Models\EmailMessage;
use App\Models\EmailThread;
use App\Models\Project;
use App\Models\ReplyTemplate;
use App\Models\User;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;

function replyThread(Project $project): EmailThread
{
    $account = EmailAccount::factory()->create(['project_id' => $project->id]);
    $folder = EmailFolder::firstOrCreate(['email_account_id' => $account->id, 'name' => 'INBOX']);

    $thread = EmailThread::factory()->create([
        'email_account_id' => $account->id,
        'project_id' => $project->id,
        'subject' => 'Vraag',
    ]);

    EmailMessage::factory()->create([
        'email_account_id' => $account->id,
        'email_folder_id' => $folder->id,
        'email_thread_id' => $thread->id,
        'direction' => EmailMessage::DIRECTION_INBOUND,
        'from_email' => 'klant@voorbeeld.nl',
        'text_body' => 'Wanneer komt mijn bestelling?',
    ]);

    return $thread;
}

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->actingAs($this->user);
    $this->project = Project::factory()->create();
});

it('assigns a thread to a teammate and clears it again', function () {
    $thread = replyThread($this->project);
    $colleague = User::factory()->create();

    $component = Livewire::test(Inbox::class, ['project' => $this->project])
        ->call('selectThread', $thread->id)
        ->call('assignThread', $colleague->id);

    expect($thread->fresh()->assignee_id)->toBe($colleague->id);

    $component->call('assignThread', null);
    expect($thread->fresh()->assignee_id)->toBeNull();
});

it('saves a project template and lists it alongside global ones', function () {
    ReplyTemplate::factory()->create(['project_id' => null, 'name' => 'Globaal sjabloon']);
    ReplyTemplate::factory()->create(['project_id' => Project::factory()->create()->id, 'name' => 'Ander project']);
    $thread = replyThread($this->project);

    $component = Livewire::test(Inbox::class, ['project' => $this->project])
        ->call('selectThread', $thread->id)
        ->set('templateName', 'Ontvangstbevestiging')
        ->set('templateBody', 'Bedankt voor je bericht.')
        ->call('saveTemplate')
        ->assertHasNoErrors();

    $names = $component->instance()->replyTemplates()->pluck('name');
    expect($names)->toContain('Ontvangstbevestiging');
    expect($names)->toContain('Globaal sjabloon');
    expect($names)->not->toContain('Ander project');
});

it('inserts a template into the reply with placeholders filled', function () {
    $thread = replyThread($this->project);
    $template = ReplyTemplate::factory()->create([
        'project_id' => $this->project->id,
        'body' => 'Beste {{contact}}, bedankt. Groet, {{agent}}',
    ]);

    Livewire::test(Inbox::class, ['project' => $this->project])
        ->call('selectThread', $thread->id)
        ->call('insertTemplate', $template->id)
        ->assertSet('replyBody', 'Beste klant@voorbeeld.nl, bedankt. Groet, '.$this->user->name);
});

it('only deletes project-scoped templates, not global ones', function () {
    $global = ReplyTemplate::factory()->create(['project_id' => null]);
    $own = ReplyTemplate::factory()->create(['project_id' => $this->project->id]);
    $thread = replyThread($this->project);

    $component = Livewire::test(Inbox::class, ['project' => $this->project])
        ->call('selectThread', $thread->id)
        ->call('deleteTemplate', $global->id)
        ->call('deleteTemplate', $own->id);

    expect(ReplyTemplate::find($global->id))->not->toBeNull();
    expect(ReplyTemplate::find($own->id))->toBeNull();
});

it('drafts a reply with AI and fills the reply body', function () {
    config()->set('services.anthropic.key', 'test-key');
    Http::fake([
        'https://api.anthropic.com/*' => Http::response([
            'content' => [['type' => 'text', 'text' => 'Beste klant, uw bestelling komt morgen aan.']],
        ], 200),
    ]);

    $thread = replyThread($this->project);

    Livewire::test(Inbox::class, ['project' => $this->project])
        ->call('selectThread', $thread->id)
        ->call('draftReply')
        ->assertSet('replyBody', 'Beste klant, uw bestelling komt morgen aan.');
});

it('shows an error and leaves the reply empty when AI is not configured', function () {
    config()->set('services.anthropic.key', '');
    $thread = replyThread($this->project);

    Livewire::test(Inbox::class, ['project' => $this->project])
        ->call('selectThread', $thread->id)
        ->call('draftReply')
        ->assertSet('replyBody', '');
});

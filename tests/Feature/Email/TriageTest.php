<?php

use App\Livewire\Email\Inbox;
use App\Models\EmailAccount;
use App\Models\EmailFolder;
use App\Models\EmailMessage;
use App\Models\EmailThread;
use App\Models\Project;
use App\Models\User;
use Livewire\Livewire;

function triageThread(Project $project, string $subject, string $from = 'klant@voorbeeld.nl'): EmailThread
{
    $account = EmailAccount::where('project_id', $project->id)->first()
        ?? EmailAccount::factory()->create(['project_id' => $project->id]);
    $folder = EmailFolder::firstOrCreate(['email_account_id' => $account->id, 'name' => 'INBOX']);

    $thread = EmailThread::factory()->create([
        'email_account_id' => $account->id,
        'project_id' => $project->id,
        'subject' => $subject,
        'last_message_at' => now(),
    ]);

    EmailMessage::factory()->create([
        'email_account_id' => $account->id,
        'email_folder_id' => $folder->id,
        'email_thread_id' => $thread->id,
        'direction' => EmailMessage::DIRECTION_INBOUND,
        'from_email' => $from,
    ]);

    return $thread;
}

beforeEach(function () {
    $this->actingAs(User::factory()->create());
    $this->project = Project::factory()->create();
});

it('archives a thread and hides it from the working inbox', function () {
    $thread = triageThread($this->project, 'Vraag');

    $component = Livewire::test(Inbox::class, ['project' => $this->project])
        ->call('archiveThread', $thread->id);

    expect($thread->fresh()->archived_at)->not->toBeNull();

    // Default inbox view excludes it; the archive view shows it.
    expect($component->instance()->groupedThreads()->flatten()->pluck('id'))->not->toContain($thread->id);

    $component->set('showArchived', true);
    expect($component->instance()->groupedThreads()->flatten()->pluck('id'))->toContain($thread->id);
});

it('snoozes a thread until a future moment and resurfaces it later', function () {
    $thread = triageThread($this->project, 'Later');

    $component = Livewire::test(Inbox::class, ['project' => $this->project])
        ->call('snoozeThread', $thread->id, 'week');

    expect($thread->fresh()->snoozed_until)->not->toBeNull();
    expect($component->instance()->groupedThreads()->flatten()->pluck('id'))->not->toContain($thread->id);

    $this->travel(8)->days();
    expect($component->instance()->groupedThreads()->flatten()->pluck('id'))->toContain($thread->id);
});

it('archives and assigns multiple threads in bulk', function () {
    $a = triageThread($this->project, 'A');
    $b = triageThread($this->project, 'B');
    $colleague = User::factory()->create();

    Livewire::test(Inbox::class, ['project' => $this->project])
        ->set('selectedThreads', [$a->id, $b->id])
        ->call('assignSelected', $colleague->id)
        ->assertSet('selectedThreads', [])
        ->set('selectedThreads', [$a->id, $b->id])
        ->call('archiveSelected');

    expect($a->fresh()->assignee_id)->toBe($colleague->id);
    expect($b->fresh()->assignee_id)->toBe($colleague->id);
    expect($a->fresh()->archived_at)->not->toBeNull();
    expect($b->fresh()->archived_at)->not->toBeNull();
});

it('shows earlier conversations from the same sender', function () {
    $current = triageThread($this->project, 'Nieuwe vraag', 'thomas@store.nl');
    $past = triageThread($this->project, 'Oude vraag', 'thomas@store.nl');
    triageThread($this->project, 'Iemand anders', 'ander@x.nl');

    $component = Livewire::test(Inbox::class, ['project' => $this->project])
        ->call('selectThread', $current->id);

    $history = $component->instance()->senderHistory()->pluck('id');
    expect($history)->toContain($past->id);
    expect($history)->not->toContain($current->id);
});

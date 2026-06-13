<?php

use App\Enums\TaskStatus;
use App\Livewire\Email\Inbox;
use App\Models\EmailAccount;
use App\Models\EmailFolder;
use App\Models\EmailMessage;
use App\Models\EmailThread;
use App\Models\Project;
use App\Models\Task;
use App\Models\User;
use Livewire\Livewire;

function threadWithInbound(Project $project, string $subject = 'Vraag over levering'): EmailThread
{
    $account = EmailAccount::factory()->create(['project_id' => $project->id]);
    $folder = EmailFolder::firstOrCreate(['email_account_id' => $account->id, 'name' => 'INBOX']);

    $thread = EmailThread::factory()->create([
        'email_account_id' => $account->id,
        'project_id' => $project->id,
        'subject' => $subject,
        'ai_summary' => 'Klant vraagt naar de levertijd.',
    ]);

    EmailMessage::factory()->create([
        'email_account_id' => $account->id,
        'email_folder_id' => $folder->id,
        'email_thread_id' => $thread->id,
        'direction' => EmailMessage::DIRECTION_INBOUND,
        'from_email' => 'klant@voorbeeld.nl',
        'text_body' => 'Wanneer wordt mijn bestelling geleverd?',
    ]);

    return $thread;
}

beforeEach(function () {
    $this->actingAs(User::factory()->create());
    $this->project = Project::factory()->create();
});

it('creates a ticket linked to the email thread', function () {
    $thread = threadWithInbound($this->project);

    Livewire::test(Inbox::class, ['project' => $this->project])
        ->call('selectThread', $thread->id)
        ->call('openTicketModal')
        ->assertSet('ticketTitle', 'Vraag over levering')
        ->set('ticketPriority', 'high')
        ->call('createTicket')
        ->assertHasNoErrors();

    $task = Task::where('email_thread_id', $thread->id)->first();
    expect($task)->not->toBeNull();
    expect($task->project_id)->toBe($this->project->id);
    expect($task->title)->toBe('Vraag over levering');
    expect($task->priority->value)->toBe('high');
    expect($task->status)->toBe(TaskStatus::Backlog);
    expect($task->description)->toContain('Klant vraagt naar de levertijd.');
    expect($task->description)->toContain('klant@voorbeeld.nl');
});

it('exposes the existing ticket so a thread is not duplicated', function () {
    $thread = threadWithInbound($this->project);

    $component = Livewire::test(Inbox::class, ['project' => $this->project])
        ->call('selectThread', $thread->id)
        ->call('openTicketModal')
        ->call('createTicket');

    expect($component->instance()->threadTicket()?->email_thread_id)->toBe($thread->id);
    expect(Task::where('email_thread_id', $thread->id)->count())->toBe(1);
});

it('falls back to a sender-based title when the subject is empty', function () {
    $thread = threadWithInbound($this->project, subject: '');

    Livewire::test(Inbox::class, ['project' => $this->project])
        ->call('selectThread', $thread->id)
        ->call('openTicketModal')
        ->assertSet('ticketTitle', 'E-mail van klant@voorbeeld.nl');
});

<?php

use App\Livewire\Email\Inbox;
use App\Livewire\NotificationBell;
use App\Models\EmailAccount;
use App\Models\EmailFolder;
use App\Models\EmailMessage;
use App\Models\EmailThread;
use App\Models\Project;
use App\Models\User;
use App\Notifications\InboxNotification;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;

function notifThread(Project $project): EmailThread
{
    $account = EmailAccount::factory()->create(['project_id' => $project->id]);
    $folder = EmailFolder::firstOrCreate(['email_account_id' => $account->id, 'name' => 'INBOX']);

    $thread = EmailThread::factory()->create([
        'email_account_id' => $account->id,
        'project_id' => $project->id,
        'subject' => 'Vraag over factuur',
    ]);

    EmailMessage::factory()->create([
        'email_account_id' => $account->id,
        'email_folder_id' => $folder->id,
        'email_thread_id' => $thread->id,
        'direction' => EmailMessage::DIRECTION_INBOUND,
        'from_email' => 'klant@voorbeeld.nl',
        'text_body' => 'Klopt mijn factuur?',
    ]);

    return $thread;
}

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->actingAs($this->user);
    $this->project = Project::factory()->create();
});

it('notifies the assignee when a thread is assigned to them', function () {
    Notification::fake();
    $thread = notifThread($this->project);
    $colleague = User::factory()->create();

    Livewire::test(Inbox::class, ['project' => $this->project])
        ->call('selectThread', $thread->id)
        ->call('assignThread', $colleague->id);

    Notification::assertSentTo($colleague, InboxNotification::class);
});

it('does not notify when assigning a thread to yourself', function () {
    Notification::fake();
    $thread = notifThread($this->project);

    Livewire::test(Inbox::class, ['project' => $this->project])
        ->call('selectThread', $thread->id)
        ->call('assignThread', $this->user->id);

    Notification::assertNothingSent();
});

it('lists unread notifications in the bell and marks them read', function () {
    $this->user->notify(new InboxNotification('Gesprek toegewezen', 'Een nieuw gesprek', '/x', 'inbox-arrow-down'));
    $id = $this->user->notifications()->first()->id;

    $bell = Livewire::test(NotificationBell::class)->assertSee('Gesprek toegewezen');
    expect($bell->instance()->unreadCount())->toBe(1);

    $bell->call('markAsRead', $id);
    expect($this->user->fresh()->unreadNotifications()->count())->toBe(0);
});

it('builds a Claude Code prompt from the thread ticket', function () {
    $project = Project::factory()->create([
        'repo_path' => '~/Herd/revboost',
        'stack' => 'Laravel 13, Livewire 4',
    ]);
    $thread = notifThread($project);

    $component = Livewire::test(Inbox::class, ['project' => $project])
        ->call('selectThread', $thread->id)
        ->call('openTicketModal')
        ->call('createTicket')
        ->call('openClaudeCodePrompt');

    $component->assertSet('claudeCodePrompt', function (string $prompt) {
        return str_contains($prompt, '~/Herd/revboost')
            && str_contains($prompt, 'Laravel 13, Livewire 4')
            && str_contains($prompt, 'Ticket')
            && str_contains($prompt, 'Vraag over factuur');
    });
});

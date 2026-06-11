<?php

use App\Enums\EmailCategory;
use App\Enums\UserRole;
use App\Livewire\Email\Inbox;
use App\Models\Client;
use App\Models\EmailAccount;
use App\Models\EmailFolder;
use App\Models\EmailMessage;
use App\Models\EmailThread;
use App\Models\Project;
use App\Models\User;
use Livewire\Livewire;

function inboxThread(EmailAccount $account, string $subject, string $category, string $body = 'Hallo daar'): EmailThread
{
    $thread = EmailThread::factory()->create([
        'email_account_id' => $account->id,
        'project_id' => $account->project_id,
        'subject' => $subject,
        'ai_category' => $category,
        'ai_summary' => "Samenvatting van {$subject}",
        'is_read' => false,
    ]);

    $folder = EmailFolder::firstOrCreate(['email_account_id' => $account->id, 'name' => 'INBOX']);
    EmailMessage::factory()->create([
        'email_account_id' => $account->id,
        'email_folder_id' => $folder->id,
        'email_thread_id' => $thread->id,
        'direction' => EmailMessage::DIRECTION_INBOUND,
        'subject' => $subject,
        'text_body' => $body,
        'status' => EmailMessage::STATUS_CATEGORISED,
    ]);

    return $thread;
}

beforeEach(function () {
    $this->user = User::factory()->create(['role' => UserRole::Member]);
    $this->actingAs($this->user);
    $this->project = Project::factory()->create();
    $this->account = EmailAccount::factory()->create(['project_id' => $this->project->id]);
});

it('lists threads grouped by category and selecting one marks it read', function () {
    $support = inboxThread($this->account, 'Inloggen lukt niet', EmailCategory::Support->value);
    inboxThread($this->account, 'Vraag over factuur', EmailCategory::Billing->value);

    Livewire::test(Inbox::class, ['project' => $this->project])
        ->assertSee('Inloggen lukt niet')
        ->assertSee('Vraag over factuur')
        ->assertSee(EmailCategory::Support->label())
        ->call('selectThread', $support->id)
        ->assertSet('selectedThreadId', $support->id)
        ->assertSee('Hallo daar');

    expect($support->refresh()->is_read)->toBeTrue();
});

it('shows an empty state when no inbox is connected', function () {
    $project = Project::factory()->create();

    Livewire::test(Inbox::class, ['project' => $project])
        ->assertSee(__('Nog geen inbox gekoppeld'));
});

it('builds the context panel on demand', function () {
    $thread = inboxThread($this->account, 'Support nodig', EmailCategory::Support->value);

    Livewire::test(Inbox::class, ['project' => $this->project])
        ->call('selectThread', $thread->id)
        ->assertSet('context', null)
        ->call('loadContext')
        ->assertSet('context', fn ($context): bool => str_contains((string) $context, 'Projectcontext'));
});

it('forbids a client from opening another client\'s inbox', function () {
    $otherClient = Client::factory()->create();
    $clientUser = User::factory()->create(['role' => UserRole::Client, 'client_id' => $otherClient->id]);

    Livewire::actingAs($clientUser)
        ->test(Inbox::class, ['project' => $this->project])
        ->assertStatus(403);
});

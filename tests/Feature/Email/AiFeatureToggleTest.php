<?php

use App\Enums\EmailCategory;
use App\Enums\UserRole;
use App\Livewire\Email\Inbox;
use App\Models\EmailAccount;
use App\Models\EmailFolder;
use App\Models\EmailMessage;
use App\Models\EmailThread;
use App\Models\Project;
use App\Models\User;
use Livewire\Livewire;

function toggleThread(EmailAccount $account, string $subject, string $category): EmailThread
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
        'text_body' => 'Hallo daar',
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

it('shows the email AI surfaces when the feature flag is on', function () {
    config(['features.ai' => true]);
    $thread = toggleThread($this->account, 'Inloggen lukt niet', EmailCategory::Billing->value);

    Livewire::test(Inbox::class, ['project' => $this->project])
        ->call('selectThread', $thread->id)
        ->assertSee(EmailCategory::Billing->label())
        ->assertSee('Samenvatting van Inloggen lukt niet')
        ->assertSee(__('AI-concept'))
        ->assertSee(__('Samenvatten met AI'))
        ->assertSee(__('Alle categorieën'));
});

it('hides the email AI surfaces when the feature flag is off', function () {
    config(['features.ai' => false]);
    $thread = toggleThread($this->account, 'Inloggen lukt niet', EmailCategory::Billing->value);

    Livewire::test(Inbox::class, ['project' => $this->project])
        ->call('selectThread', $thread->id)
        ->assertSee('Inloggen lukt niet') // thread still listed (flat)
        ->assertDontSee(EmailCategory::Billing->label())
        ->assertDontSee('Samenvatting van Inloggen lukt niet')
        ->assertDontSee(__('AI-concept'))
        ->assertDontSee(__('Samenvatten met AI'))
        ->assertDontSee(__('Alle categorieën'));
});

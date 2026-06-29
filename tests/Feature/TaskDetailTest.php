<?php

use App\Enums\TaskPriority;
use App\Enums\TaskStatus;
use App\Livewire\Tasks\TaskDetail;
use App\Models\Conversation;
use App\Models\Label;
use App\Models\Project;
use App\Models\Task;
use App\Models\User;
use App\Services\AttachmentService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->actingAs($this->user);
    $this->project = Project::factory()->create();
    $this->task = Task::factory()->for($this->project)->status(TaskStatus::Todo)->create();
});

function openDetail(): Testable
{
    return Livewire::test(TaskDetail::class)
        ->call('open', test()->task->id);
}

test('opening a task loads its fields', function () {
    openDetail()
        ->assertSet('taskId', $this->task->id)
        ->assertSet('title', $this->task->title);
});

test('editing core fields auto-saves on update', function () {
    $assignee = User::factory()->create();

    openDetail()
        ->set('title', 'Bijgewerkte titel')
        ->set('priority', TaskPriority::High->value)
        ->set('assigneeId', $assignee->id)
        ->set('dueDate', '2026-07-01')
        ->set('status', TaskStatus::InProgress->value);

    $this->task->refresh();

    expect($this->task->title)->toBe('Bijgewerkte titel')
        ->and($this->task->priority)->toBe(TaskPriority::High)
        ->and($this->task->assignee_id)->toBe($assignee->id)
        ->and($this->task->due_date->format('Y-m-d'))->toBe('2026-07-01')
        ->and($this->task->status)->toBe(TaskStatus::InProgress);
});

test('the description is saved as sanitized html', function () {
    openDetail()
        ->set('description', '<p>Hallo <strong>wereld</strong></p><script>alert(1)</script>')
        ->call('saveDescription');

    $this->task->refresh();

    expect($this->task->description)
        ->toContain('<p>Hallo <strong>wereld</strong></p>')
        ->not->toContain('<script');
});

test('a blank title fails validation', function () {
    openDetail()
        ->set('title', '')
        ->assertHasErrors(['title' => 'required']);
});

test('a label can be toggled on and off', function () {
    $label = Label::factory()->create();

    $component = openDetail()->call('toggleLabel', $label->id);
    expect($this->task->labels()->count())->toBe(1);

    $component->call('toggleLabel', $label->id);
    expect($this->task->fresh()->labels()->count())->toBe(0);
});

test('creating a label attaches it to the task', function () {
    openDetail()
        ->set('newLabelName', 'Spoed')
        ->call('createLabel');

    expect(Label::where('name', 'Spoed')->exists())->toBeTrue()
        ->and($this->task->labels()->where('name', 'Spoed')->exists())->toBeTrue();
});

test('a subtask can be added and toggled complete', function () {
    $component = openDetail()
        ->set('newSubtaskTitle', 'Eerste subtask')
        ->call('addSubtask');

    $subtask = $this->task->subtasks()->first();

    expect($subtask)->not->toBeNull()
        ->and($subtask->title)->toBe('Eerste subtask')
        ->and($subtask->parent_id)->toBe($this->task->id);

    $component->call('toggleSubtask', $subtask->id);
    expect($subtask->refresh()->status)->toBe(TaskStatus::Done);

    $component->call('toggleSubtask', $subtask->id);
    expect($subtask->refresh()->status)->toBe(TaskStatus::Todo);
});

test('a comment can be posted', function () {
    openDetail()
        ->set('newComment', 'Goed bezig!')
        ->call('addComment');

    expect($this->task->comments()->count())->toBe(1);

    $comment = $this->task->comments()->first();
    expect($comment->body)->toBe('Goed bezig!')
        ->and($comment->user_id)->toBe($this->user->id);
});

test('a rich reply with an inline image is stored and rendered inline', function () {
    openDetail()
        ->set('newComment', '<p>Zie hier <img src="http://localhost/attachments/7"></p>')
        ->call('addComment')
        ->assertSeeHtml('<img src="http://localhost/attachments/7"');

    expect($this->task->comments()->first()->body)
        ->toContain('<img src="http://localhost/attachments/7"');
});

test('an image-only reply (no text) is accepted', function () {
    openDetail()
        ->set('newComment', '<p><img src="http://localhost/attachments/9"></p>')
        ->call('addComment');

    expect($this->task->comments()->count())->toBe(1);
});

test('an empty editor reply is ignored', function () {
    openDetail()
        ->set('newComment', '<p></p>')
        ->call('addComment');

    expect($this->task->comments()->count())->toBe(0);
});

test('dangerous markup in a reply is stripped before saving', function () {
    openDetail()
        ->set('newComment', '<p>Hoi<script>alert(1)</script></p>')
        ->call('addComment');

    expect($this->task->comments()->first()->body)->not->toContain('<script>');
});

test('a reply can carry file attachments', function () {
    Storage::fake('local');

    openDetail()
        ->set('newComment', 'Zie de bijlage')
        ->set('newCommentAttachments', [UploadedFile::fake()->create('offerte.pdf', 8, 'application/pdf')])
        ->call('addComment')
        ->assertHasNoErrors();

    $comment = $this->task->comments()->first();

    // The file is linked to the comment...
    expect($comment->attachments()->count())->toBe(1)
        ->and($comment->attachments()->first()->filename)->toBe('offerte.pdf')
        // ...while still living among all of the task's attachments.
        ->and($this->task->attachments()->count())->toBe(1);
});

test('a reply with only a file and no text is accepted', function () {
    Storage::fake('local');

    openDetail()
        ->set('newCommentAttachments', [UploadedFile::fake()->image('schermafbeelding.png')])
        ->call('addComment')
        ->assertHasNoErrors();

    expect($this->task->comments()->count())->toBe(1)
        ->and($this->task->comments()->first()->attachments()->count())->toBe(1);
});

test('a pending reply attachment can be removed before sending', function () {
    Storage::fake('local');

    openDetail()
        ->set('newCommentAttachments', [
            UploadedFile::fake()->create('een.pdf', 4),
            UploadedFile::fake()->create('twee.pdf', 4),
        ])
        ->call('removeNewCommentAttachment', 0)
        ->assertCount('newCommentAttachments', 1)
        ->set('newComment', 'Klaar')
        ->call('addComment');

    expect($this->task->attachments()->count())->toBe(1);
});

test('posting a comment logs an activity', function () {
    openDetail()
        ->set('newComment', 'Goed bezig!')
        ->call('addComment');

    $activity = $this->task->activities()->where('type', 'comment')->first();

    expect($activity)->not->toBeNull()
        ->and($activity->user_id)->toBe($this->user->id)
        ->and($activity->description())->toBe('plaatste een reactie');
});

test('the comment composer is a rich editor wired to newComment with mention names', function () {
    openDetail()
        ->assertSeeHtml('data-flux-editor')
        ->assertSeeHtml('wire:model="newComment"')
        ->assertSeeHtml('data-mentions=');
});

test('the activity log is shown with a count once there is activity', function () {
    openDetail()
        ->set('newComment', 'Eerste reactie')
        ->call('addComment')
        ->assertSee('Activiteit')
        ->assertSee('plaatste een reactie');
});

test('a task can be deleted', function () {
    openDetail()->call('deleteTask');

    expect(Task::find($this->task->id))->toBeNull();
});

test('opening a subtask switches the panel to it', function () {
    $subtask = Task::factory()->subtaskOf($this->task)->create(['status' => TaskStatus::Todo]);

    openDetail()
        ->call('open', $subtask->id)
        ->assertSet('taskId', $subtask->id);
});

test('copying the ticket link dispatches the share URL to the clipboard', function () {
    openDetail()
        ->call('copyLink')
        ->assertDispatched('copy-to-clipboard', text: $this->task->ticketUrl());
});

test('copying the transcript dispatches description and replies with inline images as markdown', function () {
    $this->task->update([
        'title' => 'Wipstoel bug',
        'description' => '<p>Zie de fout hieronder.</p><p><img src="https://cdn.example.com/bug.png"></p>',
    ]);

    $author = User::factory()->create(['name' => 'Ada Lovelace']);
    $this->task->comments()->create([
        'user_id' => $author->id,
        'body' => '<p>Goed gezien, ik fix dit.</p><p><img src="https://cdn.example.com/fix.png"></p>',
    ]);

    openDetail()
        ->call('copyTranscript')
        ->assertDispatched('copy-to-clipboard', function (string $event, array $params): bool {
            $text = $params['text'];

            return str_contains($text, 'Wipstoel bug')
                && str_contains($text, '## Omschrijving')
                && str_contains($text, 'Zie de fout hieronder.')
                && str_contains($text, '![](https://cdn.example.com/bug.png)')
                && str_contains($text, '## Reacties')
                && str_contains($text, 'Ada Lovelace')
                && str_contains($text, 'Goed gezien, ik fix dit.')
                && str_contains($text, '![](https://cdn.example.com/fix.png)');
        });
});

test('the transcript references attachments via their public login-free links', function () {
    Storage::fake('local');

    $service = app(AttachmentService::class);
    $inline = $service->storeUpload(UploadedFile::fake()->image('scherm.png'), $this->task, $this->user);
    $file = $service->storeUpload(UploadedFile::fake()->create('rapport.pdf', 8, 'application/pdf'), $this->task, $this->user);

    // An inline image stored in the body uses the in-app (auth-gated) show URL.
    $this->task->update([
        'description' => '<p>Zie scherm.</p><p><img src="'.route('attachments.show', $inline).'"></p>',
    ]);

    openDetail()
        ->call('copyTranscript')
        ->assertDispatched('copy-to-clipboard', function (string $event, array $params) use ($inline, $file): bool {
            $text = $params['text'];

            return str_contains($text, $inline->publicUrl())
                && str_contains($text, $file->publicUrl())
                // The auth-gated URLs must not leak into the shareable prompt.
                && ! str_contains($text, route('attachments.show', $inline))
                && ! str_contains($text, route('attachments.download', $file));
        });
});

test('sending a ticket to a chat posts its link as a message', function () {
    $group = Conversation::factory()->create(['name' => 'Team']);
    $group->users()->sync([$this->user->id]);

    openDetail()->call('sendToChat', $group->id);

    $message = $group->messages()->latest('id')->first();

    expect($message)->not->toBeNull()
        ->and($message->user_id)->toBe($this->user->id)
        ->and($message->body)->toContain($this->task->ticketUrl());
});

test('a ticket cannot be sent to a conversation the user cannot access', function () {
    $group = Conversation::factory()->create();
    $group->users()->sync([User::factory()->create()->id]);

    openDetail()->call('sendToChat', $group->id);

    expect($group->messages()->count())->toBe(0);
});

test('saving a description keeps embedded images', function () {
    $html = '<p>Zie deze afbeelding:</p><img src="https://example.com/screenshot.png" alt="">';

    openDetail()
        ->set('description', $html)
        ->call('saveDescription');

    expect($this->task->refresh()->description)->toContain('<img')
        ->and($this->task->description)->toContain('https://example.com/screenshot.png');
});

test('pasting an image into the description stores it as a task attachment', function () {
    Storage::fake('local');

    $image = UploadedFile::fake()->image('paste.png', 40, 40);

    openDetail()
        ->set('pastedImage', $image)
        ->call('attachPastedImage')
        ->assertHasNoErrors();

    expect($this->task->attachments()->count())->toBe(1)
        ->and($this->task->attachments()->first()->isImage())->toBeTrue();
});

test('re-pasting the same image reuses the existing attachment', function () {
    Storage::fake('local');

    $bytes = UploadedFile::fake()->image('paste.png', 40, 40)->get();

    foreach (range(1, 2) as $i) {
        openDetail()
            ->set('pastedImage', UploadedFile::fake()->createWithContent("paste-{$i}.png", $bytes))
            ->call('attachPastedImage')
            ->assertHasNoErrors();
    }

    // The second paste reuses the first attachment instead of duplicating it.
    expect($this->task->attachments()->count())->toBe(1);
});

test('pasting a different image adds a separate attachment', function () {
    Storage::fake('local');

    openDetail()
        ->set('pastedImage', UploadedFile::fake()->image('one.png', 40, 40))
        ->call('attachPastedImage');

    openDetail()
        ->set('pastedImage', UploadedFile::fake()->image('two.png', 80, 80))
        ->call('attachPastedImage');

    expect($this->task->attachments()->count())->toBe(2);
});

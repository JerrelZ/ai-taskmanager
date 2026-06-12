<?php

use App\Enums\TaskPriority;
use App\Enums\TaskStatus;
use App\Livewire\Tasks\TaskDetail;
use App\Models\Label;
use App\Models\Project;
use App\Models\Task;
use App\Models\User;
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

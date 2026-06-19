<?php

use App\Enums\TaskPriority;
use App\Enums\TaskStatus;
use App\Livewire\Projects\Board;
use App\Models\Label;
use App\Models\Project;
use App\Models\Task;
use App\Models\User;
use Livewire\Livewire;

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->actingAs($this->user);
    $this->project = Project::factory()->create();
});

test('a task can be quick-created in a column', function () {
    Livewire::test(Board::class, ['project' => $this->project])
        ->set('newTaskTitle.todo', 'Quick task')
        ->call('createTask', 'todo')
        ->assertHasNoErrors();

    $task = Task::where('title', 'Quick task')->first();

    expect($task)->not->toBeNull()
        ->and($task->status)->toBe(TaskStatus::Todo)
        ->and($task->project_id)->toBe($this->project->id);
});

test('blank quick-create title does not create a task', function () {
    Livewire::test(Board::class, ['project' => $this->project])
        ->set('newTaskTitle.todo', '   ')
        ->call('createTask', 'todo');

    expect(Task::count())->toBe(0);
});

test('moving a task changes its status and orders positions', function () {
    $a = Task::factory()->for($this->project)->status(TaskStatus::Backlog)->create(['position' => 0]);
    $b = Task::factory()->for($this->project)->status(TaskStatus::Todo)->create(['position' => 0]);
    $c = Task::factory()->for($this->project)->status(TaskStatus::Todo)->create(['position' => 1]);

    // Move A into the Todo column at position 1 (between B and C).
    Livewire::test(Board::class, ['project' => $this->project])
        ->call('moveTask', $a->id, 1, 'todo');

    expect($a->refresh()->status)->toBe(TaskStatus::Todo);

    $ordered = Task::where('status', 'todo')->orderBy('position')->pluck('id')->all();
    expect($ordered)->toBe([$b->id, $a->id, $c->id]);
});

test('setting a status moves a task to the end of the target column', function () {
    $a = Task::factory()->for($this->project)->status(TaskStatus::Backlog)->create(['position' => 0]);
    $b = Task::factory()->for($this->project)->status(TaskStatus::Todo)->create(['position' => 0]);

    Livewire::test(Board::class, ['project' => $this->project])
        ->call('setStatus', $a->id, 'todo');

    expect($a->refresh()->status)->toBe(TaskStatus::Todo);

    $ordered = Task::where('status', 'todo')->orderBy('position')->pluck('id')->all();
    expect($ordered)->toBe([$b->id, $a->id]);
});

test('setting the same status leaves the task untouched', function () {
    $a = Task::factory()->for($this->project)->status(TaskStatus::Todo)->create(['position' => 3]);

    Livewire::test(Board::class, ['project' => $this->project])
        ->call('setStatus', $a->id, 'todo');

    $a->refresh();
    expect($a->status)->toBe(TaskStatus::Todo);
    expect($a->position)->toBe(3);
});

test('reordering within a column persists positions', function () {
    $a = Task::factory()->for($this->project)->status(TaskStatus::Todo)->create(['position' => 0]);
    $b = Task::factory()->for($this->project)->status(TaskStatus::Todo)->create(['position' => 1]);

    Livewire::test(Board::class, ['project' => $this->project])
        ->call('moveTask', $b->id, 0, 'todo');

    $ordered = Task::where('status', 'todo')->orderBy('position')->pluck('id')->all();
    expect($ordered)->toBe([$b->id, $a->id]);
});

test('the assignee filter limits visible tasks', function () {
    $other = User::factory()->create();
    $mine = Task::factory()->for($this->project)->status(TaskStatus::Todo)->create(['assignee_id' => $this->user->id, 'title' => 'Mijn task']);
    Task::factory()->for($this->project)->status(TaskStatus::Todo)->create(['assignee_id' => $other->id, 'title' => 'Andermans task']);

    Livewire::test(Board::class, ['project' => $this->project])
        ->set('assigneeFilter', $this->user->id)
        ->assertSee('Mijn task')
        ->assertDontSee('Andermans task');
});

test('the label filter limits visible tasks', function () {
    $label = Label::factory()->create();
    $tagged = Task::factory()->for($this->project)->status(TaskStatus::Todo)->create(['title' => 'Met label']);
    $tagged->labels()->attach($label);
    Task::factory()->for($this->project)->status(TaskStatus::Todo)->create(['title' => 'Zonder label']);

    Livewire::test(Board::class, ['project' => $this->project])
        ->set('labelFilter', $label->id)
        ->assertSee('Met label')
        ->assertDontSee('Zonder label');
});

test('the priority filter limits visible tasks', function () {
    Task::factory()->for($this->project)->status(TaskStatus::Todo)->priority(TaskPriority::Urgent)->create(['title' => 'Urgente task']);
    Task::factory()->for($this->project)->status(TaskStatus::Todo)->priority(TaskPriority::Low)->create(['title' => 'Lage task']);

    Livewire::test(Board::class, ['project' => $this->project])
        ->set('priorityFilter', 'urgent')
        ->assertSee('Urgente task')
        ->assertDontSee('Lage task');
});

test('setting a priority from the board updates it and logs an activity', function () {
    $task = Task::factory()->for($this->project)->status(TaskStatus::Todo)->priority(TaskPriority::None)->create();

    Livewire::test(Board::class, ['project' => $this->project])
        ->call('setPriority', $task->id, TaskPriority::High->value);

    $task->refresh();

    expect($task->priority)->toBe(TaskPriority::High)
        ->and($task->activities()->where('type', 'priority')->exists())->toBeTrue();
});

test('only root tasks appear on the board, not subtasks', function () {
    $parent = Task::factory()->for($this->project)->status(TaskStatus::Todo)->create(['title' => 'Parent task']);
    Task::factory()->subtaskOf($parent)->create(['title' => 'Subtask hidden', 'status' => TaskStatus::Todo]);

    Livewire::test(Board::class, ['project' => $this->project])
        ->assertSee('Parent task')
        ->assertDontSee('Subtask hidden');
});

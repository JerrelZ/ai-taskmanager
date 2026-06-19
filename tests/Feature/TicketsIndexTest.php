<?php

use App\Enums\TaskPriority;
use App\Enums\TaskStatus;
use App\Livewire\Tickets\Index;
use App\Models\Client;
use App\Models\Label;
use App\Models\Project;
use App\Models\Task;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Livewire\Livewire;

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->actingAs($this->user);
    $this->project = Project::factory()->create();
});

test('the tickets view lists actionable tickets across projects ordered by rank', function () {
    $other = Project::factory()->create();

    Task::factory()->for($this->project)->status(TaskStatus::Todo)->create(['title' => 'Eerste', 'rank' => 0]);
    Task::factory()->for($other)->status(TaskStatus::Backlog)->create(['title' => 'Tweede', 'rank' => 1]);

    Livewire::test(Index::class)
        ->assertSeeInOrder(['Eerste', 'Tweede']);
});

test('completed tickets are hidden by default and shown when toggled', function () {
    Task::factory()->for($this->project)->status(TaskStatus::Todo)->create(['title' => 'Open ticket']);
    Task::factory()->for($this->project)->status(TaskStatus::Done)->create(['title' => 'Klaar ticket']);

    Livewire::test(Index::class)
        ->assertSee('Open ticket')
        ->assertDontSee('Klaar ticket')
        ->set('showCompleted', true)
        ->assertSee('Klaar ticket');
});

test('subtasks never appear in the global tickets list', function () {
    $parent = Task::factory()->for($this->project)->status(TaskStatus::Todo)->create(['title' => 'Parent ticket']);
    Task::factory()->subtaskOf($parent)->create(['title' => 'Child ticket', 'status' => TaskStatus::Todo]);

    Livewire::test(Index::class)
        ->assertSee('Parent ticket')
        ->assertDontSee('Child ticket');
});

test('the now task is the highest ranked actionable ticket', function () {
    Task::factory()->for($this->project)->status(TaskStatus::Todo)->create(['title' => 'Lager', 'rank' => 5]);
    $top = Task::factory()->for($this->project)->status(TaskStatus::Todo)->create(['title' => 'Bovenaan', 'rank' => 0]);

    Livewire::test(Index::class)
        ->assertSet('nowTask.id', $top->id);
});

test('reordering sets absolute global rank across projects', function () {
    $other = Project::factory()->create();

    $a = Task::factory()->for($this->project)->status(TaskStatus::Todo)->create(['rank' => 0]);
    $b = Task::factory()->for($other)->status(TaskStatus::Todo)->create(['rank' => 1]);
    $c = Task::factory()->for($this->project)->status(TaskStatus::Todo)->create(['rank' => 2]);

    Livewire::test(Index::class)
        ->call('reorder', $c->id, 0);

    $order = Task::query()->roots()->actionable()->orderBy('rank')->pluck('id')->all();

    expect($order)->toBe([$c->id, $a->id, $b->id])
        ->and($c->refresh()->rank)->toBe(0);
});

test('a filter can limit tickets by project', function () {
    $other = Project::factory()->create();
    Task::factory()->for($this->project)->status(TaskStatus::Todo)->create(['title' => 'Mijn project ticket']);
    Task::factory()->for($other)->status(TaskStatus::Todo)->create(['title' => 'Ander project ticket']);

    Livewire::test(Index::class)
        ->set('projectFilter', $this->project->id)
        ->assertSee('Mijn project ticket')
        ->assertDontSee('Ander project ticket');
});

test('setting a priority from the tickets list updates it and logs an activity', function () {
    $task = Task::factory()->for($this->project)->status(TaskStatus::Todo)->priority(TaskPriority::None)->create();

    Livewire::test(Index::class)
        ->call('setPriority', $task->id, TaskPriority::Urgent->value);

    $task->refresh();

    expect($task->priority)->toBe(TaskPriority::Urgent)
        ->and($task->activities()->where('type', 'priority')->exists())->toBeTrue();
});

test('a ticket outside the workspace cannot have its priority changed', function () {
    $otherWorkspace = Workspace::factory()->create();
    $otherProject = Project::factory()->create(['workspace_id' => $otherWorkspace->id]);
    $task = Task::factory()->for($otherProject)->status(TaskStatus::Todo)->priority(TaskPriority::None)->create();

    expect(fn () => Livewire::test(Index::class)->call('setPriority', $task->id, TaskPriority::Urgent->value))
        ->toThrow(ModelNotFoundException::class);

    expect($task->refresh()->priority)->toBe(TaskPriority::None);
});

test('marking a ticket reviewed updates reviewed_at', function () {
    $task = Task::factory()->for($this->project)->status(TaskStatus::Todo)->create(['reviewed_at' => null]);

    Livewire::test(Index::class)
        ->call('markReviewed', $task->id);

    expect($task->refresh()->reviewed_at)->not->toBeNull();
});

test('copying a prompt dispatches the clipboard event', function () {
    $this->actingAs(User::factory()->canCopyPrompt()->create());

    $task = Task::factory()->for($this->project)->status(TaskStatus::Todo)->create();

    Livewire::test(Index::class)
        ->call('copyPrompt', $task->id)
        ->assertDispatched('copy-to-clipboard');
});

test('the status can be set inline from the tickets list', function () {
    $task = Task::factory()->for($this->project)->status(TaskStatus::Todo)->create();

    Livewire::test(Index::class)->call('setStatus', $task->id, TaskStatus::InProgress->value);

    expect($task->refresh()->status)->toBe(TaskStatus::InProgress)
        ->and($task->activities()->where('type', 'status')->exists())->toBeTrue();
});

test('the assignee can be set inline from the tickets list', function () {
    $mate = User::factory()->create();
    $task = Task::factory()->for($this->project)->status(TaskStatus::Todo)->create();

    Livewire::test(Index::class)->call('setAssignee', $task->id, $mate->id);

    expect($task->refresh()->assignee_id)->toBe($mate->id)
        ->and($task->activities()->where('type', 'assignee')->exists())->toBeTrue();
});

test('an assignee from another workspace is rejected inline', function () {
    $outsider = User::factory()->create(['workspace_id' => Workspace::factory()->create()->id]);
    $task = Task::factory()->for($this->project)->status(TaskStatus::Todo)->create(['assignee_id' => null]);

    Livewire::test(Index::class)->call('setAssignee', $task->id, $outsider->id);

    expect($task->refresh()->assignee_id)->toBeNull();
});

test('the deadline can be set and cleared inline from the tickets list', function () {
    $task = Task::factory()->for($this->project)->status(TaskStatus::Todo)->create(['due_date' => null]);

    $component = Livewire::test(Index::class)->call('setDue', $task->id, '2026-07-01');
    expect($task->refresh()->due_date->format('Y-m-d'))->toBe('2026-07-01');

    $component->call('setDue', $task->id, null);
    expect($task->refresh()->due_date)->toBeNull();
});

test('a label can be toggled inline from the tickets list', function () {
    $label = Label::factory()->create();
    $task = Task::factory()->for($this->project)->status(TaskStatus::Todo)->create();

    $component = Livewire::test(Index::class)->call('toggleLabel', $task->id, $label->id);
    expect($task->labels()->count())->toBe(1);

    $component->call('toggleLabel', $task->id, $label->id);
    expect($task->fresh()->labels()->count())->toBe(0);
});

test('bulk setting status updates every selected ticket', function () {
    $a = Task::factory()->for($this->project)->status(TaskStatus::Todo)->create();
    $b = Task::factory()->for($this->project)->status(TaskStatus::Backlog)->create();

    Livewire::test(Index::class)
        ->set('selectedTickets', [$a->id, $b->id])
        ->call('bulkSetStatus', TaskStatus::Done->value)
        ->assertSet('selectedTickets', []);

    expect($a->refresh()->status)->toBe(TaskStatus::Done)
        ->and($b->refresh()->status)->toBe(TaskStatus::Done);
});

test('bulk assigning updates every selected ticket', function () {
    $mate = User::factory()->create();
    $a = Task::factory()->for($this->project)->status(TaskStatus::Todo)->create();
    $b = Task::factory()->for($this->project)->status(TaskStatus::Todo)->create();

    Livewire::test(Index::class)
        ->set('selectedTickets', [$a->id, $b->id])
        ->call('bulkSetAssignee', $mate->id);

    expect($a->refresh()->assignee_id)->toBe($mate->id)
        ->and($b->refresh()->assignee_id)->toBe($mate->id);
});

test('bulk adding a label attaches it to every selected ticket', function () {
    $label = Label::factory()->create();
    $a = Task::factory()->for($this->project)->status(TaskStatus::Todo)->create();
    $b = Task::factory()->for($this->project)->status(TaskStatus::Todo)->create();

    Livewire::test(Index::class)
        ->set('selectedTickets', [$a->id, $b->id])
        ->call('bulkAddLabel', $label->id);

    expect($a->labels()->count())->toBe(1)
        ->and($b->labels()->count())->toBe(1);
});

test('bulk marking reviewed touches every selected ticket', function () {
    $a = Task::factory()->for($this->project)->status(TaskStatus::Todo)->create(['reviewed_at' => null]);
    $b = Task::factory()->for($this->project)->status(TaskStatus::Todo)->create(['reviewed_at' => null]);

    Livewire::test(Index::class)
        ->set('selectedTickets', [$a->id, $b->id])
        ->call('bulkMarkReviewed');

    expect($a->refresh()->reviewed_at)->not->toBeNull()
        ->and($b->refresh()->reviewed_at)->not->toBeNull();
});

test('bulk deleting removes every selected ticket', function () {
    $a = Task::factory()->for($this->project)->status(TaskStatus::Todo)->create();
    $b = Task::factory()->for($this->project)->status(TaskStatus::Todo)->create();

    Livewire::test(Index::class)
        ->set('selectedTickets', [$a->id, $b->id])
        ->call('bulkDelete');

    expect(Task::find($a->id))->toBeNull()
        ->and(Task::find($b->id))->toBeNull();
});

test('bulk actions never reach a ticket in another workspace', function () {
    $otherWorkspace = Workspace::factory()->create();
    $otherProject = Project::factory()->create(['workspace_id' => $otherWorkspace->id]);
    $outside = Task::factory()->for($otherProject)->status(TaskStatus::Todo)->create();

    Livewire::test(Index::class)
        ->set('selectedTickets', [$outside->id])
        ->call('bulkDelete');

    expect(Task::find($outside->id))->not->toBeNull();
});

test('a client cannot bulk edit even tickets they can see', function () {
    $client = Client::factory()->create();
    $clientProject = Project::factory()->create(['client_id' => $client->id]);
    $task = Task::factory()->for($clientProject)->status(TaskStatus::Todo)->create();

    $this->actingAs(User::factory()->client($client)->create());

    Livewire::test(Index::class)
        ->set('selectedTickets', [$task->id])
        ->call('bulkDelete');

    expect(Task::find($task->id))->not->toBeNull();
});

test('the only-stale filter shows only stale tickets', function () {
    // Rank the stale ticket first so the "now" block shows it, not the fresh one.
    $stale = Task::factory()->for($this->project)->status(TaskStatus::Todo)->create(['title' => 'Oude ticket', 'rank' => 0]);
    $stale->forceFill(['updated_at' => now()->subDays(30)])->saveQuietly();
    Task::factory()->for($this->project)->status(TaskStatus::Todo)->create(['title' => 'Verse ticket', 'rank' => 1]);

    Livewire::test(Index::class)
        ->set('onlyStale', true)
        ->assertSee('Oude ticket')
        ->assertDontSee('Verse ticket');
});

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
    $this->user = User::factory()->admin()->create();
    $this->actingAs($this->user);
    $this->project = Project::factory()->create();
});

test('a status column orders tickets across projects by their shared position', function () {
    $other = Project::factory()->create();

    Task::factory()->for($this->project)->status(TaskStatus::Todo)->create(['title' => 'Eerste', 'position' => 0]);
    Task::factory()->for($other)->status(TaskStatus::Todo)->create(['title' => 'Tweede', 'position' => 1]);

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

test('the now task is the top of the most active column', function () {
    Task::factory()->for($this->project)->status(TaskStatus::Todo)->create(['title' => 'Lager', 'position' => 5]);
    $top = Task::factory()->for($this->project)->status(TaskStatus::Todo)->create(['title' => 'Bovenaan', 'position' => 0]);

    Livewire::test(Index::class)
        ->assertSet('nowTask.id', $top->id);
});

test('an in-progress ticket outranks the todo column for the now task', function () {
    Task::factory()->for($this->project)->status(TaskStatus::Todo)->create(['position' => 0]);
    $busy = Task::factory()->for($this->project)->status(TaskStatus::InProgress)->create(['position' => 0]);

    Livewire::test(Index::class)
        ->assertSet('nowTask.id', $busy->id);
});

test('moving a ticket sets its workspace-global position across projects', function () {
    $other = Project::factory()->create();

    $a = Task::factory()->for($this->project)->status(TaskStatus::Todo)->create(['position' => 0]);
    $b = Task::factory()->for($other)->status(TaskStatus::Todo)->create(['position' => 1]);
    $c = Task::factory()->for($this->project)->status(TaskStatus::Todo)->create(['position' => 2]);

    Livewire::test(Index::class)
        ->call('moveTask', $c->id, 0, 'todo');

    $order = Task::query()->roots()->where('status', 'todo')->orderBy('position')->pluck('id')->all();

    expect($order)->toBe([$c->id, $a->id, $b->id])
        ->and($c->refresh()->position)->toBe(0);
});

test('reordering on the global board is reflected on the project board', function () {
    $a = Task::factory()->for($this->project)->status(TaskStatus::Todo)->create(['title' => 'Project A1', 'position' => 0]);
    Task::factory()->for(Project::factory()->create())->status(TaskStatus::Todo)->create(['title' => 'Other', 'position' => 1]);
    $b = Task::factory()->for($this->project)->status(TaskStatus::Todo)->create(['title' => 'Project A2', 'position' => 2]);

    // Pull A2 above A1 on the global board (slot 0 of the Todo column).
    Livewire::test(Index::class)->call('moveTask', $b->id, 0, 'todo');

    // The project board, ordered by the same position, shows A2 before A1.
    $projectOrder = $this->project->rootTasks()->where('status', 'todo')->orderBy('position')->pluck('id')->all();

    expect($projectOrder)->toBe([$b->id, $a->id]);
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

test('bulk moving tickets to another project renumbers them and carries subtasks along', function () {
    $other = Project::factory()->create();
    // An existing ticket in the target project forces the moved ticket onto a fresh number.
    Task::factory()->for($other)->status(TaskStatus::Todo)->create();

    $task = Task::factory()->for($this->project)->status(TaskStatus::Todo)->create();
    $subtask = Task::factory()->for($this->project)->create(['parent_id' => $task->id]);

    Livewire::test(Index::class)
        ->set('selectedTickets', [$task->id])
        ->call('bulkSetProject', $other->id)
        ->assertSet('selectedTickets', []);

    expect($task->refresh()->project_id)->toBe($other->id)
        ->and($subtask->refresh()->project_id)->toBe($other->id);

    $numbers = Task::where('project_id', $other->id)->pluck('number');
    expect($numbers->count())->toBe($numbers->unique()->count());
});

test('bulk moving ignores a project the user cannot see', function () {
    $otherWorkspace = Workspace::factory()->create();
    $foreignProject = Project::factory()->create(['workspace_id' => $otherWorkspace->id]);
    $task = Task::factory()->for($this->project)->status(TaskStatus::Todo)->create();

    Livewire::test(Index::class)
        ->set('selectedTickets', [$task->id])
        ->call('bulkSetProject', $foreignProject->id);

    expect($task->refresh()->project_id)->toBe($this->project->id);
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
    // Position the stale ticket first so the "now" block shows it, not the fresh one.
    $stale = Task::factory()->for($this->project)->status(TaskStatus::Todo)->create(['title' => 'Oude ticket', 'position' => 0]);
    $stale->forceFill(['updated_at' => now()->subDays(30)])->saveQuietly();
    Task::factory()->for($this->project)->status(TaskStatus::Todo)->create(['title' => 'Verse ticket', 'position' => 1]);

    Livewire::test(Index::class)
        ->set('onlyStale', true)
        ->assertSee('Oude ticket')
        ->assertDontSee('Verse ticket');
});

test('the column plus creates a ticket in the chosen project and status', function () {
    Livewire::test(Index::class)
        ->call('openNewTicket', TaskStatus::Todo->value)
        ->assertSet('newTicketStatus', TaskStatus::Todo->value)
        ->set('newTicketProjectId', $this->project->id)
        ->set('newTicketTitle', 'Nieuw vanaf bord')
        ->call('createTicket')
        ->assertHasNoErrors();

    $task = Task::where('title', 'Nieuw vanaf bord')->first();

    expect($task)->not->toBeNull()
        ->and($task->project_id)->toBe($this->project->id)
        ->and($task->status)->toBe(TaskStatus::Todo)
        ->and($task->created_by)->toBe($this->user->id);
});

test('creating a ticket from the board requires a title', function () {
    Livewire::test(Index::class)
        ->call('openNewTicket', TaskStatus::Todo->value)
        ->set('newTicketProjectId', $this->project->id)
        ->set('newTicketTitle', '')
        ->call('createTicket')
        ->assertHasErrors('newTicketTitle');
});

test('tickets can be sorted by creation date, newest or oldest first', function () {
    Task::factory()->for($this->project)->status(TaskStatus::Todo)->create(['title' => 'Oud ticket', 'position' => 0, 'created_at' => now()->subDays(3)]);
    Task::factory()->for($this->project)->status(TaskStatus::Todo)->create(['title' => 'Nieuw ticket', 'position' => 1, 'created_at' => now()->subDay()]);

    Livewire::test(Index::class)
        ->assertSeeInOrder(['Oud ticket', 'Nieuw ticket'])
        ->set('sortBy', 'created_desc')
        ->assertSeeInOrder(['Nieuw ticket', 'Oud ticket'])
        ->set('sortBy', 'created_asc')
        ->assertSeeInOrder(['Oud ticket', 'Nieuw ticket']);
});

test('tickets can be sorted by priority and by deadline, with undated tickets last', function () {
    Task::factory()->for($this->project)->status(TaskStatus::Todo)->create(['title' => 'Laag zonder deadline', 'position' => 0, 'priority' => TaskPriority::Low, 'due_date' => null]);
    Task::factory()->for($this->project)->status(TaskStatus::Todo)->create(['title' => 'Urgent later', 'position' => 1, 'priority' => TaskPriority::Urgent, 'due_date' => now()->addWeek()]);
    Task::factory()->for($this->project)->status(TaskStatus::Todo)->create(['title' => 'Medium morgen', 'position' => 2, 'priority' => TaskPriority::Medium, 'due_date' => now()->addDay()]);

    Livewire::test(Index::class)
        ->set('sortBy', 'priority')
        ->assertSeeInOrder(['Urgent later', 'Medium morgen', 'Laag zonder deadline'])
        ->set('sortBy', 'due_asc')
        ->assertSeeInOrder(['Medium morgen', 'Urgent later', 'Laag zonder deadline']);
});

test('an unknown sort mode falls back to the manual order', function () {
    Task::factory()->for($this->project)->status(TaskStatus::Todo)->create(['title' => 'Eerste', 'position' => 0, 'created_at' => now()->subDays(3)]);
    Task::factory()->for($this->project)->status(TaskStatus::Todo)->create(['title' => 'Tweede', 'position' => 1]);

    Livewire::test(Index::class)
        ->set('sortBy', 'bestaat-niet')
        ->assertSeeInOrder(['Eerste', 'Tweede']);
});

test('dragging while sorted moves a ticket to another column but never re-ranks it', function () {
    $first = Task::factory()->for($this->project)->status(TaskStatus::Todo)->create(['position' => 0]);
    $second = Task::factory()->for($this->project)->status(TaskStatus::Todo)->create(['position' => 1]);

    $component = Livewire::test(Index::class)->set('sortBy', 'created_desc');

    // Same column: the hand-dragged order is left alone.
    $component->call('moveTask', $second->id, 0, TaskStatus::Todo->value);

    expect($first->fresh()->position)->toBe(0)
        ->and($second->fresh()->position)->toBe(1);

    // Other column: the status changes.
    $component->call('moveTask', $second->id, 0, TaskStatus::InProgress->value);

    expect($second->fresh()->status)->toBe(TaskStatus::InProgress)
        ->and($first->fresh()->position)->toBe(0);
});

test('the search finds a ticket by its number or full identifier', function () {
    $project = Project::factory()->create(['key' => 'WEB']);
    $match = Task::factory()->for($project)->status(TaskStatus::Todo)->create(['title' => 'Gezocht ticket']);
    Task::factory()->for($project)->status(TaskStatus::Todo)->create(['title' => 'Ander ticket']);

    Livewire::test(Index::class)
        ->set('search', 'WEB-'.$match->number)
        ->assertSee('Gezocht ticket')
        ->assertDontSee('Ander ticket')
        ->set('search', '#'.$match->number)
        ->assertSee('Gezocht ticket')
        ->set('search', 'ander')
        ->assertSee('Ander ticket')
        ->assertDontSee('Gezocht ticket');
});

test('the my-tickets toggle filters on the current user and back', function () {
    Task::factory()->for($this->project)->status(TaskStatus::Todo)->create(['title' => 'Van mij', 'assignee_id' => $this->user->id]);
    Task::factory()->for($this->project)->status(TaskStatus::Todo)->create(['title' => 'Van niemand', 'assignee_id' => null]);

    Livewire::test(Index::class)
        ->call('toggleMine')
        ->assertSet('assigneeFilter', $this->user->id)
        ->assertSee('Van mij')
        ->assertDontSee('Van niemand')
        ->call('toggleMine')
        ->assertSet('assigneeFilter', null)
        ->assertSee('Van niemand');
});

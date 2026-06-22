<?php

use App\Enums\TaskStatus;
use App\Events\TaskBoardUpdated;
use App\Livewire\Projects\Board;
use App\Livewire\Tickets\Index;
use App\Models\Project;
use App\Models\Task;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Support\Facades\Broadcast;
use Illuminate\Support\Facades\Event;
use Livewire\Livewire;

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->actingAs($this->user);
    $this->project = Project::factory()->create();
});

test('the board updated event broadcasts on the workspace channel', function () {
    $event = new TaskBoardUpdated(42);

    expect($event->broadcastOn()[0]->name)->toBe('private-workspace.42.board')
        ->and($event->broadcastAs())->toBe('board.updated');
});

test('only members of the workspace may subscribe to the board channel', function () {
    $outsider = User::factory()->create(['workspace_id' => Workspace::factory()->create()->id]);

    $callback = Broadcast::getChannels()['workspace.{workspaceId}.board'] ?? null;

    expect($callback)->not->toBeNull()
        ->and($callback($this->user, $this->user->workspace_id))->toBeTrue()
        ->and($callback($outsider, $this->user->workspace_id))->toBeFalse();
});

test('reordering on the global board broadcasts a live update', function () {
    Event::fake([TaskBoardUpdated::class]);

    Task::factory()->for($this->project)->status(TaskStatus::Todo)->create(['position' => 0]);
    $b = Task::factory()->for($this->project)->status(TaskStatus::Todo)->create(['position' => 1]);

    Livewire::test(Index::class)->call('moveTask', $b->id, 0, 'todo');

    Event::assertDispatched(
        TaskBoardUpdated::class,
        fn (TaskBoardUpdated $event) => $event->workspaceId === $this->project->workspace_id
    );
});

test('reordering on the project board broadcasts a live update', function () {
    Event::fake([TaskBoardUpdated::class]);

    Task::factory()->for($this->project)->status(TaskStatus::Todo)->create(['position' => 0]);
    $b = Task::factory()->for($this->project)->status(TaskStatus::Todo)->create(['position' => 1]);

    Livewire::test(Board::class, ['project' => $this->project])->call('moveTask', $b->id, 0, 'todo');

    Event::assertDispatched(TaskBoardUpdated::class);
});

test('creating a ticket on the project board broadcasts a live update', function () {
    Event::fake([TaskBoardUpdated::class]);

    Livewire::test(Board::class, ['project' => $this->project])
        ->set('newTaskTitle.todo', 'Verse ticket')
        ->call('createTask', 'todo');

    Event::assertDispatched(TaskBoardUpdated::class);
});

test('the global board subscribes to its workspace live stream', function () {
    $channel = "echo-private:workspace.{$this->user->workspace_id}.board,.board.updated";

    expect(Livewire::test(Index::class)->instance()->getListeners())
        ->toHaveKey($channel);
});

test('the poll fallback picks up a ticket added by another process', function () {
    $component = Livewire::test(Index::class)->assertDontSee('Buitenom toegevoegd');

    // Simulate a change made elsewhere (another user / Reverb down).
    Task::factory()->for($this->project)->status(TaskStatus::Todo)->create(['title' => 'Buitenom toegevoegd']);

    $component->call('pollBoard')->assertSee('Buitenom toegevoegd');
});

test('the project board poll fallback also catches new tickets', function () {
    $component = Livewire::test(Board::class, ['project' => $this->project])->assertDontSee('Buitenom toegevoegd');

    Task::factory()->for($this->project)->status(TaskStatus::Todo)->create(['title' => 'Buitenom toegevoegd']);

    $component->call('pollBoard')->assertSee('Buitenom toegevoegd');
});

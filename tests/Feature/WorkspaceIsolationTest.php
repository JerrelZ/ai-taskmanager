<?php

use App\Enums\UserRole;
use App\Livewire\Tasks\TaskDetail;
use App\Livewire\Tickets\Index as TicketsIndex;
use App\Models\Client;
use App\Models\Project;
use App\Models\Task;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Livewire\Livewire;

/**
 * Two isolated workspaces, each with a team member, used across the IDOR tests.
 */
function twoWorkspaces(): array
{
    $wsA = Workspace::factory()->create();
    $wsB = Workspace::factory()->create();

    $userA = User::factory()->create(['workspace_id' => $wsA->id, 'role' => UserRole::Member]);

    $projectB = Project::factory()->create(['workspace_id' => $wsB->id]);
    $taskB = Task::factory()->for($projectB)->create(['title' => 'Geheim van B']);

    return [$userA, $taskB];
}

it('does not expose a task from another workspace through a tampered taskId', function () {
    [$userA, $taskB] = twoWorkspaces();

    Livewire::actingAs($userA)
        ->test(TaskDetail::class)
        ->set('taskId', $taskB->id)
        ->assertDontSee('Geheim van B');
});

it('refuses to mutate a task from another workspace via a tampered taskId', function () {
    [$userA, $taskB] = twoWorkspaces();

    Livewire::actingAs($userA)
        ->test(TaskDetail::class)
        ->set('taskId', $taskB->id)
        ->set('title', 'Overgenomen')
        ->call('saveTask');

    expect($taskB->fresh()->title)->toBe('Geheim van B');
});

it('refuses to delete a task from another workspace via a tampered taskId', function () {
    [$userA, $taskB] = twoWorkspaces();

    Livewire::actingAs($userA)
        ->test(TaskDetail::class)
        ->set('taskId', $taskB->id)
        ->call('deleteTask');

    expect(Task::whereKey($taskB->id)->exists())->toBeTrue();
});

it('refuses to mark a foreign task as reviewed from the tickets list', function () {
    [$userA, $taskB] = twoWorkspaces();

    expect(fn () => Livewire::actingAs($userA)->test(TicketsIndex::class)->call('markReviewed', $taskB->id))
        ->toThrow(ModelNotFoundException::class);

    expect($taskB->fresh()->reviewed_at)->toBeNull();
});

it('does not reorder a foreign task on the global board', function () {
    [$userA, $taskB] = twoWorkspaces();
    $positionBefore = $taskB->position;

    Livewire::actingAs($userA)->test(TicketsIndex::class)->call('moveTask', $taskB->id, 0, $taskB->status->value);

    expect($taskB->fresh()->position)->toBe($positionBefore);
});

it('hides another client\'s task from a client in the same workspace', function () {
    $workspace = Workspace::factory()->create();
    $clientA = Client::factory()->create(['workspace_id' => $workspace->id]);
    $clientB = Client::factory()->create(['workspace_id' => $workspace->id]);

    $clientUser = User::factory()->create([
        'workspace_id' => $workspace->id,
        'role' => UserRole::Client,
        'client_id' => $clientA->id,
    ]);

    $projectB = Project::factory()->create(['workspace_id' => $workspace->id, 'client_id' => $clientB->id]);
    $taskB = Task::factory()->for($projectB)->create(['title' => 'Andere klant']);

    Livewire::actingAs($clientUser)
        ->test(TaskDetail::class)
        ->set('taskId', $taskB->id)
        ->set('title', 'Gestolen')
        ->call('saveTask');

    expect($taskB->fresh()->title)->toBe('Andere klant');
});

it('refuses access to a project channel from another workspace', function () {
    $wsA = Workspace::factory()->create();
    $wsB = Workspace::factory()->create();

    $teamA = User::factory()->create(['workspace_id' => $wsA->id, 'role' => UserRole::Member]);
    $projectB = Project::factory()->create(['workspace_id' => $wsB->id]);

    expect($projectB->channel()->canAccess($teamA))->toBeFalse();
});

it('still lets a user open and edit a task in their own workspace', function () {
    $workspace = Workspace::factory()->create();
    $user = User::factory()->create(['workspace_id' => $workspace->id, 'role' => UserRole::Member]);
    $project = Project::factory()->create(['workspace_id' => $workspace->id]);
    $task = Task::factory()->for($project)->create(['title' => 'Eigen taak']);

    Livewire::actingAs($user)
        ->test(TaskDetail::class)
        ->call('open', $task->id)
        ->set('title', 'Bijgewerkt')
        ->call('saveTask');

    expect($task->fresh()->title)->toBe('Bijgewerkt');
});

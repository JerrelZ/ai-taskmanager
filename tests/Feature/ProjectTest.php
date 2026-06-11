<?php

use App\Enums\TaskStatus;
use App\Livewire\Projects\Board;
use App\Livewire\Projects\Index;
use App\Models\Project;
use App\Models\Task;
use App\Models\User;
use Livewire\Livewire;

beforeEach(function () {
    $this->actingAs(User::factory()->create());
});

test('the projects index lists projects with open task counts', function () {
    $project = Project::factory()->create(['name' => 'Alpha']);
    Task::factory()->for($project)->status(TaskStatus::Todo)->create();
    Task::factory()->for($project)->status(TaskStatus::Done)->create();

    Livewire::test(Index::class)
        ->assertSee('Alpha')
        ->assertSee('1 open');
});

test('a project can be created and redirects to its board', function () {
    Livewire::test(Index::class)
        ->set('name', 'Nieuw Project')
        ->set('color', 'green')
        ->call('createProject')
        ->assertHasNoErrors()
        ->assertRedirect();

    expect(Project::where('name', 'Nieuw Project')->where('color', 'green')->exists())->toBeTrue();
});

test('creating a project requires a name', function () {
    Livewire::test(Index::class)
        ->set('name', '')
        ->call('createProject')
        ->assertHasErrors(['name' => 'required']);
});

test('the board renders both kanban and list views', function () {
    $project = Project::factory()->create();
    $task = Task::factory()->for($project)->status(TaskStatus::Todo)->create(['title' => 'Zichtbare task']);

    Livewire::test(Board::class, ['project' => $project])
        ->assertSee('Zichtbare task')
        ->assertSet('boardView', 'kanban')
        ->set('boardView', 'list')
        ->assertSee('Zichtbare task');
});

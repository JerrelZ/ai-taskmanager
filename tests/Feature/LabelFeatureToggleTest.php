<?php

use App\Enums\TaskStatus;
use App\Livewire\Projects\Board;
use App\Livewire\Tasks\TaskDetail;
use App\Models\Project;
use App\Models\Task;
use App\Models\User;
use Livewire\Livewire;

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->actingAs($this->user);
    $this->project = Project::factory()->create();
    $this->task = Task::factory()->for($this->project)->status(TaskStatus::Todo)->create();
});

it('shows the ticket label UI when the feature flag is on', function () {
    config(['features.labels' => true]);

    Livewire::test(TaskDetail::class)
        ->call('open', $this->task->id)
        ->assertSee(__('Nieuw label...'));

    Livewire::test(Board::class, ['project' => $this->project])
        ->assertSee(__('Alle labels'));
});

it('hides the ticket label UI when the feature flag is off', function () {
    config(['features.labels' => false]);

    Livewire::test(TaskDetail::class)
        ->call('open', $this->task->id)
        ->assertDontSee(__('Nieuw label...'));

    Livewire::test(Board::class, ['project' => $this->project])
        ->assertDontSee(__('Alle labels'));
});

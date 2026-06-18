<?php

use App\Livewire\Tasks\TaskDetail;
use App\Models\Project;
use App\Models\Task;
use App\Models\User;
use Livewire\Livewire;

beforeEach(function () {
    $this->project = Project::factory()->create();
    $this->task = Task::factory()->for($this->project)->create();
});

test('a user without the feature can not copy a prompt', function () {
    $this->actingAs(User::factory()->create());

    Livewire::test(TaskDetail::class)
        ->call('open', $this->task->id)
        ->call('copyPrompt', $this->task->id)
        ->assertStatus(403)
        ->assertNotDispatched('copy-to-clipboard');
});

test('a user with the feature can copy a prompt', function () {
    $this->actingAs(User::factory()->canCopyPrompt()->create());

    Livewire::test(TaskDetail::class)
        ->call('open', $this->task->id)
        ->call('copyPrompt', $this->task->id)
        ->assertDispatched('copy-to-clipboard');
});

test('the feature flag defaults to off for new users', function () {
    expect(User::factory()->create()->canCopyPrompt())->toBeFalse();
});

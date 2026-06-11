<?php

use App\Enums\TaskStatus;
use App\Models\Project;
use App\Models\Task;

beforeEach(function () {
    $this->project = Project::factory()->create();
});

test('an open task untouched beyond the threshold is stale', function () {
    $task = Task::factory()->for($this->project)->status(TaskStatus::Todo)->create();
    $task->forceFill(['updated_at' => now()->subDays(Task::STALE_AFTER_DAYS + 1)])->saveQuietly();

    expect($task->isStale())->toBeTrue();
});

test('a recently touched task is not stale', function () {
    $task = Task::factory()->for($this->project)->status(TaskStatus::Todo)->create();

    expect($task->isStale())->toBeFalse();
});

test('a completed task is never stale', function () {
    $task = Task::factory()->for($this->project)->status(TaskStatus::Done)->create();
    $task->forceFill(['updated_at' => now()->subDays(60)])->saveQuietly();

    expect($task->isStale())->toBeFalse();
});

test('marking reviewed resets staleness', function () {
    $task = Task::factory()->for($this->project)->status(TaskStatus::Todo)->create();
    $task->forceFill(['updated_at' => now()->subDays(60)])->saveQuietly();

    expect($task->isStale())->toBeTrue();

    $task->update(['reviewed_at' => now()]);

    expect($task->refresh()->isStale())->toBeFalse();
});

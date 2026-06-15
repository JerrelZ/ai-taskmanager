<?php

use App\Enums\TaskReadiness;
use App\Enums\TaskStatus;
use App\Jobs\AssessTaskPromptReadiness;
use App\Models\Project;
use App\Models\Task;
use App\Services\TaskReadinessAssessor;
use Illuminate\Support\Facades\Bus;

it('dispatches an assessment when a ticket is created', function () {
    Bus::fake([AssessTaskPromptReadiness::class]);

    $task = Task::factory()->create();

    Bus::assertDispatched(
        AssessTaskPromptReadiness::class,
        fn (AssessTaskPromptReadiness $job) => $job->taskId === $task->id,
    );
});

it('re-assesses when the title, description or project changes', function () {
    $task = Task::factory()->create();

    Bus::fake([AssessTaskPromptReadiness::class]);

    $task->update(['title' => 'Een nieuwe titel']);
    Bus::assertDispatchedTimes(AssessTaskPromptReadiness::class, 1);

    $task->update(['description' => 'Nieuwe omschrijving']);
    Bus::assertDispatchedTimes(AssessTaskPromptReadiness::class, 2);
});

it('does not re-assess for unrelated changes', function () {
    $task = Task::factory()->create();

    Bus::fake([AssessTaskPromptReadiness::class]);

    $task->update(['position' => 99]);

    Bus::assertNotDispatched(AssessTaskPromptReadiness::class);
});

it('does not loop when the assessment writes its own columns back', function () {
    $project = Project::factory()->create(['repo_path' => '~/Herd/demo']);
    $task = Task::factory()->for($project)->create(['description' => 'Duidelijk genoeg.']);

    Bus::fake([AssessTaskPromptReadiness::class]);

    (new AssessTaskPromptReadiness($task->id))->handle(app(TaskReadinessAssessor::class));

    Bus::assertNotDispatched(AssessTaskPromptReadiness::class);
});

it('caches the readiness result on the task', function () {
    $project = Project::factory()->create(['repo_path' => '~/Herd/demo']);
    $task = Task::factory()->for($project)->create([
        'description' => 'Duidelijke omschrijving.',
        'status' => TaskStatus::Todo,
    ]);

    (new AssessTaskPromptReadiness($task->id))->handle(app(TaskReadinessAssessor::class));

    $task->refresh();

    expect($task->ai_readiness)->toBe(TaskReadiness::Ready)
        ->and($task->ai_assessed_at)->not->toBeNull()
        ->and($task->ai_prompt)->toContain($task->title)
        ->and($task->ai_missing)->toBe([]);
});

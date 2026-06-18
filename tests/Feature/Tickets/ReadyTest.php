<?php

use App\Enums\TaskReadiness;
use App\Enums\TaskStatus;
use App\Jobs\AssessTaskPromptReadiness;
use App\Livewire\Tickets\Ready;
use App\Models\Project;
use App\Models\Task;
use App\Models\User;
use Illuminate\Support\Facades\Bus;
use Livewire\Livewire;

beforeEach(function () {
    $this->actingAs(User::factory()->create());
});

it('lists ready tickets and hides not-ready ones', function () {
    $project = Project::factory()->create(['repo_path' => '~/Herd/demo']);

    // Passes the rule gate -> heuristic marks it ready on create.
    $ready = Task::factory()->for($project)->create([
        'title' => 'Repareer de checkout',
        'description' => 'De checkout-knop doet niets op mobiel.',
        'status' => TaskStatus::Todo,
    ]);

    // No description -> assessor marks it not ready.
    $notReady = Task::factory()->for($project)->create([
        'title' => 'Vage taak',
        'description' => null,
        'status' => TaskStatus::Todo,
    ]);

    expect($ready->fresh()->ai_readiness)->toBe(TaskReadiness::Ready);

    Livewire::test(Ready::class)
        ->assertSee('Repareer de checkout')
        ->assertDontSee('Vage taak');
});

it('shows almost-ready tickets with their missing context', function () {
    $project = Project::factory()->create(['repo_path' => '~/Herd/demo']);
    $task = Task::factory()->for($project)->create([
        'title' => 'Bijna klaar ticket',
        'description' => 'Iets',
        'status' => TaskStatus::Todo,
    ]);

    $task->update([
        'ai_readiness' => TaskReadiness::Almost->value,
        'ai_missing' => ['Geen reproductiestappen genoemd.'],
    ]);

    Livewire::test(Ready::class)
        ->assertSee('Bijna klaar ticket')
        ->assertSee('Geen reproductiestappen genoemd.');
});

it('copies the resolved prompt to the clipboard', function () {
    $this->actingAs(User::factory()->canCopyPrompt()->create());

    $project = Project::factory()->create(['repo_path' => '~/Herd/demo']);
    $task = Task::factory()->for($project)->create([
        'title' => 'Kopieerbaar ticket',
        'description' => 'Duidelijke omschrijving.',
    ]);

    Livewire::test(Ready::class)
        ->call('copyPrompt', $task->id)
        ->assertDispatched('copy-to-clipboard');
});

it('can re-trigger an assessment on demand', function () {
    $project = Project::factory()->create(['repo_path' => '~/Herd/demo']);
    $task = Task::factory()->for($project)->create(['description' => 'Iets']);

    Bus::fake([AssessTaskPromptReadiness::class]);

    Livewire::test(Ready::class)->call('reassess', $task->id);

    Bus::assertDispatched(
        AssessTaskPromptReadiness::class,
        fn (AssessTaskPromptReadiness $job) => $job->taskId === $task->id,
    );
});

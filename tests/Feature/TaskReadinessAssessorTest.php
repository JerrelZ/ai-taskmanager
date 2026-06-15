<?php

use App\Enums\TaskReadiness;
use App\Enums\TaskStatus;
use App\Models\Project;
use App\Models\Task;
use App\Services\TaskReadinessAssessor;
use Illuminate\Support\Facades\Http;

function assessor(): TaskReadinessAssessor
{
    return app(TaskReadinessAssessor::class);
}

it('blocks tickets whose project has no repository path', function () {
    $project = Project::factory()->create(['repo_path' => null]);
    $task = Task::factory()->for($project)->create(['description' => 'Iets duidelijks']);

    $result = assessor()->assess($task);

    expect($result['readiness'])->toBe(TaskReadiness::NotReady)
        ->and($result['ai'])->toBeFalse()
        ->and($result['missing'])->toContain('Het project heeft geen repository-pad ingesteld.');
});

it('blocks tickets without a description', function () {
    $project = Project::factory()->create(['repo_path' => '~/Herd/demo']);
    $task = Task::factory()->for($project)->create(['description' => null]);

    $result = assessor()->assess($task);

    expect($result['readiness'])->toBe(TaskReadiness::NotReady)
        ->and($result['missing'])->toContain('Ticket heeft geen omschrijving — alleen een titel is te weinig context.');
});

it('blocks completed tickets', function () {
    $project = Project::factory()->create(['repo_path' => '~/Herd/demo']);
    $task = Task::factory()->for($project)->create([
        'description' => 'Iets duidelijks',
        'status' => TaskStatus::Done,
    ]);

    expect(assessor()->assess($task)['readiness'])->toBe(TaskReadiness::NotReady);
});

it('falls back to a heuristic ready when no API key is configured', function () {
    config(['services.anthropic.key' => '']);

    $project = Project::factory()->create(['repo_path' => '~/Herd/demo']);
    $task = Task::factory()->for($project)->create([
        'description' => 'Duidelijke omschrijving van de bug.',
        'status' => TaskStatus::Todo,
    ]);

    $result = assessor()->assess($task);

    expect($result['readiness'])->toBe(TaskReadiness::Ready)
        ->and($result['ai'])->toBeFalse()
        ->and($result['prompt'])->toContain($task->title);
});

it('uses Claude to score readiness and sharpen the prompt', function () {
    config(['services.anthropic.key' => 'test-key']);

    Http::fake([
        'api.anthropic.com/*' => Http::response([
            'content' => [[
                'type' => 'text',
                'text' => json_encode([
                    'readiness' => 'almost',
                    'missing' => ['Geen reproductiestappen genoemd.'],
                    'prompt' => 'Aangescherpte plak-klare prompt.',
                ]),
            ]],
        ]),
    ]);

    $project = Project::factory()->create(['repo_path' => '~/Herd/demo']);
    $task = Task::factory()->for($project)->create([
        'description' => 'De knop werkt niet.',
        'status' => TaskStatus::Todo,
    ]);

    $result = assessor()->assess($task);

    expect($result['readiness'])->toBe(TaskReadiness::Almost)
        ->and($result['ai'])->toBeTrue()
        ->and($result['missing'])->toContain('Geen reproductiestappen genoemd.')
        ->and($result['prompt'])->toBe('Aangescherpte plak-klare prompt.');
});

it('falls back to the heuristic when the API call fails', function () {
    config(['services.anthropic.key' => 'test-key']);

    Http::fake(['api.anthropic.com/*' => Http::response('boom', 500)]);

    $project = Project::factory()->create(['repo_path' => '~/Herd/demo']);
    $task = Task::factory()->for($project)->create([
        'description' => 'Duidelijke omschrijving.',
        'status' => TaskStatus::Todo,
    ]);

    $result = assessor()->assess($task);

    expect($result['readiness'])->toBe(TaskReadiness::Ready)
        ->and($result['ai'])->toBeFalse();
});

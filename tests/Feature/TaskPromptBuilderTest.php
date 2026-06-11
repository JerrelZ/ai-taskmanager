<?php

use App\Enums\TaskPriority;
use App\Enums\TaskStatus;
use App\Models\Comment;
use App\Models\Label;
use App\Models\Project;
use App\Models\Task;
use App\Models\User;
use App\Services\TaskPromptBuilder;

test('it renders a task as a markdown prompt with full context', function () {
    $project = Project::factory()->create([
        'name' => 'Website Redesign',
        'repo_path' => '~/Herd/website',
        'stack' => 'Laravel, Livewire',
        'context' => 'SSR Blade, Pest tests.',
    ]);

    $assignee = User::factory()->create(['name' => 'Sanne']);

    $task = Task::factory()->for($project)->create([
        'title' => 'Fix checkout',
        'description' => 'The checkout button is broken.',
        'status' => TaskStatus::InProgress,
        'priority' => TaskPriority::High,
        'assignee_id' => $assignee->id,
        'due_date' => '2026-07-01',
    ]);

    $label = Label::factory()->create(['name' => 'Bug']);
    $task->labels()->attach($label);

    Task::factory()->subtaskOf($task)->create(['title' => 'Reproduce', 'status' => TaskStatus::Done]);
    Task::factory()->subtaskOf($task)->create(['title' => 'Write test', 'status' => TaskStatus::Todo]);

    Comment::factory()->for($task)->create([
        'user_id' => $assignee->id,
        'body' => 'Happens only on mobile.',
    ]);

    $prompt = app(TaskPromptBuilder::class)->build($task);

    expect($prompt)
        ->toContain('# '.$task->identifier().': Fix checkout')
        ->toContain('Website Redesign')
        ->toContain('~/Herd/website')
        ->toContain('Laravel, Livewire')
        ->toContain('SSR Blade, Pest tests.')
        ->toContain('**Status:** In Progress')
        ->toContain('**Priority:** High')
        ->toContain('**Assignee:** Sanne')
        ->toContain('**Due:** 2026-07-01')
        ->toContain('**Labels:** Bug')
        ->toContain('The checkout button is broken.')
        ->toContain('- [x] Reproduce')
        ->toContain('- [ ] Write test')
        ->toContain('Happens only on mobile.');
});

test('it omits optional sections when data is missing', function () {
    $project = Project::factory()->create(['repo_path' => null, 'stack' => null, 'context' => null]);
    $task = Task::factory()->for($project)->create([
        'title' => 'Bare task',
        'description' => null,
        'assignee_id' => null,
        'due_date' => null,
    ]);

    $prompt = app(TaskPromptBuilder::class)->build($task);

    expect($prompt)
        ->toContain($task->identifier().': Bare task')
        ->not->toContain('**Repository:**')
        ->not->toContain('**Assignee:**')
        ->not->toContain('## Subtasks')
        ->not->toContain('## Discussion');
});

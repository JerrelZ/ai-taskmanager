<?php

use App\Jobs\RunClaudeCodeForTask;
use App\Livewire\Email\Inbox;
use App\Models\ClaudeCodeRun;
use App\Models\EmailAccount;
use App\Models\EmailFolder;
use App\Models\EmailMessage;
use App\Models\EmailThread;
use App\Models\Project;
use App\Models\Task;
use App\Models\User;
use App\Services\ClaudeCodeRunner;
use Illuminate\Support\Facades\Bus;
use Livewire\Livewire;

beforeEach(function () {
    $this->actingAs(User::factory()->create());
});

function ticketThread(Project $project): array
{
    $account = EmailAccount::factory()->create(['project_id' => $project->id]);
    $folder = EmailFolder::firstOrCreate(['email_account_id' => $account->id, 'name' => 'INBOX']);
    $thread = EmailThread::factory()->create(['email_account_id' => $account->id, 'project_id' => $project->id]);
    EmailMessage::factory()->create([
        'email_account_id' => $account->id,
        'email_folder_id' => $folder->id,
        'email_thread_id' => $thread->id,
        'direction' => EmailMessage::DIRECTION_INBOUND,
        'from_email' => 'klant@x.nl',
    ]);
    $task = $project->tasks()->create([
        'email_thread_id' => $thread->id,
        'title' => 'Fix login',
        'status' => \App\Enums\TaskStatus::Backlog,
        'priority' => \App\Enums\TaskPriority::None,
        'position' => 0,
    ]);

    return [$thread, $task];
}

it('starts a Claude Code run from the thread ticket', function () {
    Bus::fake([RunClaudeCodeForTask::class]);
    $project = Project::factory()->create(['repo_path' => '~/Herd/demo']);
    [$thread, $task] = ticketThread($project);

    Livewire::test(Inbox::class, ['project' => $project])
        ->call('selectThread', $thread->id)
        ->call('runClaudeCode');

    $run = ClaudeCodeRun::where('task_id', $task->id)->first();
    expect($run)->not->toBeNull();
    expect($run->status)->toBe(ClaudeCodeRun::STATUS_PENDING);
    Bus::assertDispatched(RunClaudeCodeForTask::class, fn ($job) => $job->claudeCodeRunId === $run->id);
});

it('records the runner output on success', function () {
    $this->mock(ClaudeCodeRunner::class, function ($mock) {
        $mock->shouldReceive('run')->once()->andReturn('Analyse: het ligt aan de auth-guard.');
    });

    $project = Project::factory()->create(['repo_path' => '~/Herd/demo']);
    [, $task] = ticketThread($project);
    $run = ClaudeCodeRun::create(['task_id' => $task->id, 'status' => 'pending', 'prompt' => 'doe iets']);

    (new RunClaudeCodeForTask($run->id))->handle(app(ClaudeCodeRunner::class));

    $run->refresh();
    expect($run->status)->toBe(ClaudeCodeRun::STATUS_COMPLETED);
    expect($run->output)->toContain('auth-guard');
    expect($run->finished_at)->not->toBeNull();
});

it('marks the run failed when the runner throws', function () {
    $this->mock(ClaudeCodeRunner::class, function ($mock) {
        $mock->shouldReceive('run')->andThrow(new RuntimeException('binary not found'));
    });

    $project = Project::factory()->create(['repo_path' => '~/Herd/demo']);
    [, $task] = ticketThread($project);
    $run = ClaudeCodeRun::create(['task_id' => $task->id, 'status' => 'pending', 'prompt' => 'x']);

    (new RunClaudeCodeForTask($run->id))->handle(app(ClaudeCodeRunner::class));

    expect($run->fresh()->status)->toBe(ClaudeCodeRun::STATUS_FAILED);
    expect($run->fresh()->error)->toContain('binary not found');
});

it('fails the run when the project has no repository path', function () {
    $project = Project::factory()->create(['repo_path' => null]);
    [, $task] = ticketThread($project);
    $run = ClaudeCodeRun::create(['task_id' => $task->id, 'status' => 'pending', 'prompt' => 'x']);

    (new RunClaudeCodeForTask($run->id))->handle(app(ClaudeCodeRunner::class));

    expect($run->fresh()->status)->toBe(ClaudeCodeRun::STATUS_FAILED);
    expect($run->fresh()->error)->toContain('repository-pad');
});

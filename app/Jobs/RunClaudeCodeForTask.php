<?php

namespace App\Jobs;

use App\Models\ClaudeCodeRun;
use App\Services\ClaudeCodeRunner;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

/**
 * Executes a queued Claude Code run for a ticket and records the result, so the
 * email-to-ticket-to-code loop closes inside the app.
 */
class RunClaudeCodeForTask implements ShouldQueue
{
    use Queueable;

    public int $timeout = 1200;

    public function __construct(public readonly int $claudeCodeRunId) {}

    public function handle(ClaudeCodeRunner $runner): void
    {
        $run = ClaudeCodeRun::with('task.project')->find($this->claudeCodeRunId);

        if ($run === null) {
            return;
        }

        $repoPath = $run->task?->project?->repo_path;

        if (blank($repoPath)) {
            $run->forceFill([
                'status' => ClaudeCodeRun::STATUS_FAILED,
                'error' => 'Het project heeft geen repository-pad ingesteld.',
                'finished_at' => now(),
            ])->save();

            return;
        }

        $run->forceFill(['status' => ClaudeCodeRun::STATUS_RUNNING])->save();

        try {
            $output = $runner->run($repoPath, $run->prompt);

            $run->forceFill([
                'status' => ClaudeCodeRun::STATUS_COMPLETED,
                'output' => $output,
                'finished_at' => now(),
            ])->save();
        } catch (Throwable $e) {
            $run->forceFill([
                'status' => ClaudeCodeRun::STATUS_FAILED,
                'error' => $e->getMessage(),
                'finished_at' => now(),
            ])->save();
        }
    }
}

<?php

namespace App\Jobs;

use App\Models\Task;
use App\Services\TaskReadinessAssessor;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Scores a ticket's prompt-readiness and caches the result on the task, so the
 * "Klaar voor Claude Code" overview reads plain columns instead of paying for
 * an assessment on every render. Best-effort: a failure here never blocks the
 * ticket from being saved.
 */
class AssessTaskPromptReadiness implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public function __construct(public readonly int $taskId) {}

    public function handle(TaskReadinessAssessor $assessor): void
    {
        $task = Task::with('project')->find($this->taskId);

        if ($task === null) {
            return;
        }

        $result = $assessor->assess($task);

        $task->forceFill([
            'ai_readiness' => $result['readiness']->value,
            'ai_missing' => $result['missing'],
            'ai_prompt' => $result['prompt'],
            'ai_assessed_at' => now(),
        ])->save();
    }
}

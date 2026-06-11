<?php

namespace App\Livewire\Concerns;

use App\Models\Task;
use App\Services\TaskPromptBuilder;
use Flux\Flux;

trait CopiesTaskPrompt
{
    /**
     * Build the AI prompt for a task and push it to the browser clipboard.
     */
    public function copyPrompt(int $taskId): void
    {
        $task = Task::findOrFail($taskId);

        $prompt = app(TaskPromptBuilder::class)->build($task);

        $this->dispatch('copy-to-clipboard', text: $prompt);

        Flux::toast(text: __('Prompt gekopieerd — plak in Claude Code.'), variant: 'success');
    }
}

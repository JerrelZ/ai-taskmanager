<?php

namespace App\Livewire\Concerns;

use App\Models\Task;
use Flux\Flux;

trait CopiesTaskPrompt
{
    /**
     * Push the task's best available AI prompt to the browser clipboard — the
     * AI-sharpened version when assessed, otherwise the freshly built one.
     */
    public function copyPrompt(int $taskId): void
    {
        $task = Task::findOrFail($taskId);

        $this->dispatch('copy-to-clipboard', text: $task->resolvedPrompt());

        Flux::toast(text: __('Prompt gekopieerd — plak in Claude Code.'), variant: 'success');
    }
}

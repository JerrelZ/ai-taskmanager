<?php

namespace App\Livewire\Concerns;

use App\Models\Task;
use Flux\Flux;
use Illuminate\Support\Facades\Auth;

trait CopiesTaskPrompt
{
    /**
     * Push the task's best available AI prompt to the browser clipboard — the
     * AI-sharpened version when assessed, otherwise the freshly built one.
     *
     * Restricted to users with the copy-prompt feature enabled.
     */
    public function copyPrompt(int $taskId): void
    {
        abort_unless(Auth::user()?->canCopyPrompt() ?? false, 403);

        $task = Task::findOrFail($taskId);

        $this->dispatch('copy-to-clipboard', text: $task->resolvedPrompt());

        Flux::toast(text: __('Prompt gekopieerd — plak in Claude Code.'), variant: 'success');
    }
}

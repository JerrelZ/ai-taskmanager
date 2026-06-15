<?php

namespace App\Services;

use App\Models\Task;

class TaskPromptBuilder
{
    /**
     * Render a task and all of its context as a Markdown prompt
     * ready to paste into an AI coding assistant (e.g. Claude Code).
     */
    public function build(Task $task): string
    {
        $task->loadMissing(['project', 'assignee', 'labels', 'subtasks', 'comments.user', 'parent']);

        $lines = [];

        $lines[] = '# '.$task->identifier().': '.$task->title;
        $lines[] = '';

        $lines[] = $this->projectSection($task);
        $lines[] = $this->metaSection($task);

        if (filled($task->description)) {
            $lines[] = '## Description';
            $lines[] = trim($task->description);
            $lines[] = '';
        }

        if ($task->subtasks->isNotEmpty()) {
            $lines[] = '## Subtasks';
            foreach ($task->subtasks as $subtask) {
                $box = $subtask->isComplete() ? '[x]' : '[ ]';
                $lines[] = "- {$box} {$subtask->title}";
            }
            $lines[] = '';
        }

        if ($task->comments->isNotEmpty()) {
            $lines[] = '## Discussion';
            foreach ($task->comments as $comment) {
                $author = $comment->user?->name ?? 'Unknown';
                $when = $comment->created_at?->format('Y-m-d H:i') ?? '';
                $lines[] = "**{$author}** ({$when}):";
                $lines[] = trim($comment->body);
                $lines[] = '';
            }
        }

        $lines[] = '---';
        $lines[] = 'Pak dit ticket op. Gebruik de hierboven genoemde context (repository, conventies, eventuele database-ids) waar relevant. Vraag om verduidelijking als iets onduidelijk is voordat je grote wijzigingen maakt.';

        return implode("\n", $lines);
    }

    private function projectSection(Task $task): string
    {
        $project = $task->project;

        $lines = ['## Project context'];
        $lines[] = "- **Project:** {$project->name}";

        if (filled($project->repo_path)) {
            $lines[] = "- **Repository:** {$project->repo_path}";
        }

        if (filled($project->stack)) {
            $lines[] = "- **Stack:** {$project->stack}";
        }

        if (filled($project->context)) {
            $lines[] = "- **Conventions:** {$project->context}";
        }

        return implode("\n", $lines)."\n";
    }

    private function metaSection(Task $task): string
    {
        $lines = ['## Details'];
        $lines[] = '- **Status:** '.$task->status->label();
        $lines[] = '- **Priority:** '.$task->priority->label();

        if ($task->assignee !== null) {
            $lines[] = '- **Assignee:** '.$task->assignee->name;
        }

        if ($task->due_date !== null) {
            $lines[] = '- **Due:** '.$task->due_date->format('Y-m-d');
        }

        if ($task->labels->isNotEmpty()) {
            $lines[] = '- **Labels:** '.$task->labels->pluck('name')->implode(', ');
        }

        if ($task->isSubtask() && $task->parent !== null) {
            $lines[] = '- **Part of:** '.$task->parent->title;
        }

        return implode("\n", $lines)."\n";
    }
}

<?php

namespace App\Mcp\Tools;

use App\Enums\TaskPriority;
use App\Enums\TaskStatus;
use App\Models\Task;
use App\Support\ProjectResolver;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tool;

#[Description('Create a follow-up task in a project (in this app, not the external database). Use this to turn an email or a database finding into an action item.')]
class CreateProjectTaskTool extends Tool
{
    public function handle(Request $request): Response|ResponseFactory
    {
        $validated = $request->validate([
            'project' => ['required', 'string', 'max:100'],
            'title' => ['required', 'string', 'max:255'],
            'description' => ['sometimes', 'nullable', 'string', 'max:5000'],
            'status' => ['sometimes', 'string', 'in:backlog,todo,in_progress,done,canceled'],
            'priority' => ['sometimes', 'string', 'in:none,urgent,high,medium,low'],
        ], [
            'project.required' => 'Specify the project key, name, or id (e.g. "REV" or "Revboost").',
            'title.required' => 'Provide a short, actionable task title.',
        ]);

        $project = ProjectResolver::find($validated['project']);

        if ($project === null) {
            return Response::error("No project found matching \"{$validated['project']}\". Call \"list-projects\" first.");
        }

        $status = TaskStatus::from($validated['status'] ?? TaskStatus::Backlog->value);
        $priority = TaskPriority::from($validated['priority'] ?? TaskPriority::None->value);

        $maxPosition = (int) $project->rootTasks()->where('status', $status->value)->max('position');

        /** @var Task $task */
        $task = $project->tasks()->create([
            'title' => $validated['title'],
            'description' => $validated['description'] ?? null,
            'status' => $status,
            'priority' => $priority,
            'position' => $maxPosition + 1,
            'created_by' => $request->user()?->id,
        ]);

        return Response::structured([
            'summary' => "Created task {$task->identifier()}: {$task->title} ({$status->label()}).",
            'id' => $task->id,
            'identifier' => $task->identifier(),
            'title' => $task->title,
            'status' => $status->value,
            'priority' => $priority->value,
            'project' => $project->key ?? $project->name,
        ]);
    }

    /**
     * @return array<string, JsonSchema>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'project' => $schema->string()
                ->description('Project key, name, or id (e.g. "REV" or "Revboost").')
                ->required(),
            'title' => $schema->string()
                ->description('Short, actionable task title.')
                ->required(),
            'description' => $schema->string()
                ->description('Optional longer description or context for the task.'),
            'status' => $schema->string()
                ->enum(['backlog', 'todo', 'in_progress', 'done', 'canceled'])
                ->description('Initial status. Defaults to "backlog".')
                ->default('backlog'),
            'priority' => $schema->string()
                ->enum(['none', 'urgent', 'high', 'medium', 'low'])
                ->description('Task priority. Defaults to "none".')
                ->default('none'),
        ];
    }
}

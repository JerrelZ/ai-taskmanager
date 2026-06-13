<?php

namespace App\Mcp\Tools;

use App\Models\EmailAccount;
use App\Models\Project;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tool;

#[Description('List the projects and which of them have an external customer database and a connected inbox. Use this to discover the project key to pass to the other tools.')]
class ListProjectsTool extends Tool
{
    public function handle(Request $request): Response|ResponseFactory
    {
        $withDb = EmailAccount::query()
            ->whereNotNull('external_db_dsn')
            ->pluck('project_id')
            ->all();

        $withInbox = EmailAccount::query()->pluck('project_id')->all();

        $projects = Project::query()
            ->orderBy('name')
            ->get(['id', 'key', 'name'])
            ->map(fn (Project $project): array => [
                'key' => $project->key,
                'name' => $project->name,
                'id' => $project->id,
                'has_external_database' => in_array($project->id, $withDb, true),
                'has_inbox' => in_array($project->id, $withInbox, true),
            ])
            ->all();

        return Response::structured([
            'summary' => count($projects).' project(s). Use the "key" with the other tools.',
            'projects' => $projects,
        ]);
    }

    /**
     * @return array<string, JsonSchema>
     */
    public function schema(JsonSchema $schema): array
    {
        return [];
    }
}

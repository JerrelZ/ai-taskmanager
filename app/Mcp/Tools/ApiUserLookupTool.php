<?php

namespace App\Mcp\Tools;

use App\Services\Email\ExternalProjectApi;
use App\Support\ProjectResolver;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Str;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tool;

#[Description('Look up a customer in the project\'s external support API by email address. Returns business-meaningful user records (id, account type, status). Prefer this over raw SQL when the project has an API configured.')]
class ApiUserLookupTool extends Tool
{
    public function handle(Request $request, ExternalProjectApi $api): Response|ResponseFactory
    {
        $validated = $request->validate([
            'project' => ['required', 'string', 'max:100'],
            'email' => ['required', 'email', 'max:255'],
        ]);

        $project = ProjectResolver::find($validated['project']);

        if ($project === null) {
            return Response::error("No project found matching \"{$validated['project']}\".");
        }

        $account = ProjectResolver::account($project);

        if ($account === null || ! $api->configured($account)) {
            return Response::error("Project \"{$project->name}\" has no support API configured. Use \"query-project-database\" or \"lookup-contact-by-email\" instead.");
        }

        try {
            $users = $api->lookupUserByEmail($account, $validated['email']);
        } catch (\Throwable $e) {
            return Response::error('API lookup failed: '.Str::limit($e->getMessage(), 300));
        }

        return Response::structured([
            'summary' => count($users)." user(s) found for {$validated['email']}.",
            'email' => $validated['email'],
            'users' => $users,
        ]);
    }

    /**
     * @return array<string, JsonSchema>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'project' => $schema->string()->description('Project key, name, or id (e.g. "REV").')->required(),
            'email' => $schema->string()->description('The email address to look up.')->required(),
        ];
    }
}

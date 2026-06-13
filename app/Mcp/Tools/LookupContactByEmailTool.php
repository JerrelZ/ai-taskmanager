<?php

namespace App\Mcp\Tools;

use App\Models\EmailContactLink;
use App\Services\Email\ContactLinkSuggester;
use App\Services\Email\ExternalProjectDb;
use App\Support\ProjectResolver;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Str;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tool;

#[Description('Find a contact in a project\'s external customer database by their email address. Scans the schema for email-like columns and returns the matching rows. Read-only.')]
class LookupContactByEmailTool extends Tool
{
    public function handle(Request $request, ExternalProjectDb $externalDb, ContactLinkSuggester $suggester): Response|ResponseFactory
    {
        $validated = $request->validate([
            'project' => ['required', 'string', 'max:100'],
            'email' => ['required', 'email', 'max:255'],
        ], [
            'project.required' => 'Specify the project key, name, or id (e.g. "REV" or "Revboost").',
            'email.required' => 'Provide the email address to look up.',
            'email.email' => 'Provide a valid email address.',
        ]);

        $project = ProjectResolver::find($validated['project']);

        if ($project === null) {
            return Response::error("No project found matching \"{$validated['project']}\". Call \"list-projects\" first.");
        }

        $account = ProjectResolver::account($project);

        if ($account === null || blank($account->external_db_dsn)) {
            return Response::error("Project \"{$project->name}\" has no external database configured.");
        }

        // A confirmed link is authoritative — return it instead of guessing.
        $link = EmailContactLink::where('email_account_id', $account->id)
            ->where('email', $validated['email'])
            ->first();

        if ($link !== null) {
            try {
                $resolved = $suggester->resolve($link);
            } catch (\Throwable) {
                $resolved = null;
            }

            return Response::structured([
                'summary' => "Confirmed link for {$validated['email']} → {$link->external_table} ({$link->external_id_column}={$link->external_id}).",
                'email' => $validated['email'],
                'confirmed_link' => true,
                'table' => $link->external_table,
                'id_column' => $link->external_id_column,
                'id' => $link->external_id,
                'label' => $resolved['label'] ?? $link->label,
                'row' => $resolved['fields'] ?? null,
            ]);
        }

        try {
            $matches = $externalDb->findByEmail($account, $validated['email']);
        } catch (\Throwable $e) {
            return Response::error('Lookup failed: '.Str::limit($e->getMessage(), 300));
        }

        $summary = $matches === []
            ? "No confirmed link and no rows matched {$validated['email']}. Use \"query-project-database\" to search further."
            : count($matches)." possible match(es) for {$validated['email']} (not yet confirmed).";

        return Response::structured([
            'summary' => $summary,
            'email' => $validated['email'],
            'confirmed_link' => false,
            'matches' => $matches,
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
            'email' => $schema->string()
                ->description('The email address to search for, typically the sender of an inbound message.')
                ->required(),
        ];
    }
}

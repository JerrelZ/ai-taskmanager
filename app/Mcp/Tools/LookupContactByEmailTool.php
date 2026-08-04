<?php

namespace App\Mcp\Tools;

use App\Models\EmailContactLink;
use App\Services\Email\ContactLinkSuggester;
use App\Services\Email\ExternalProjectApi;
use App\Support\ProjectResolver;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Str;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tool;

#[Description('Find a contact for a project by email address, via that project\'s read-only support API. Returns the confirmed link when one exists, otherwise the matching customer records.')]
class LookupContactByEmailTool extends Tool
{
    public function handle(Request $request, ExternalProjectApi $api, ContactLinkSuggester $suggester): Response|ResponseFactory
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

        if ($account === null || ! $api->configured($account)) {
            return Response::error("Project \"{$project->name}\" has no support API configured.");
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
                'summary' => "Confirmed link for {$validated['email']} → {$link->external_resource} #{$link->external_id}.",
                'email' => $validated['email'],
                'confirmed_link' => true,
                'resource' => $link->external_resource,
                'id' => $link->external_id,
                'label' => $resolved['label'] ?? $link->label,
                'record' => $resolved['fields'] ?? null,
            ]);
        }

        try {
            $matches = $suggester->suggest($account, $validated['email']);
        } catch (\Throwable $e) {
            return Response::error('Lookup failed: '.Str::limit($e->getMessage(), 300));
        }

        $summary = $matches === []
            ? "No confirmed link and no customer matched {$validated['email']}."
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

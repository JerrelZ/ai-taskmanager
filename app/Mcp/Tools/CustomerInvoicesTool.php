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

#[Description('Get a customer\'s invoices (and their paid/unpaid/overdue status) from the project\'s external support API. Useful for billing questions.')]
class CustomerInvoicesTool extends Tool
{
    public function handle(Request $request, ExternalProjectApi $api): Response|ResponseFactory
    {
        $validated = $request->validate([
            'project' => ['required', 'string', 'max:100'],
            'user_id' => ['required', 'string', 'max:64'],
            'status' => ['sometimes', 'string', 'in:pending,paid'],
        ]);

        $project = ProjectResolver::find($validated['project']);
        $account = $project !== null ? ProjectResolver::account($project) : null;

        if ($account === null || ! $api->configured($account)) {
            return Response::error('No support API configured for this project.');
        }

        try {
            $invoices = $api->invoices($account, $validated['user_id'], $validated['status'] ?? null);
        } catch (\Throwable $e) {
            return Response::error('API request failed: '.Str::limit($e->getMessage(), 300));
        }

        return Response::structured([
            'summary' => "Invoices for user {$validated['user_id']}.",
            'user_id' => $validated['user_id'],
            'data' => $invoices,
        ]);
    }

    /**
     * @return array<string, JsonSchema>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'project' => $schema->string()->description('Project key, name, or id (e.g. "REV").')->required(),
            'user_id' => $schema->string()->description('The external user id.')->required(),
            'status' => $schema->string()->enum(['pending', 'paid'])->description('Filter by invoice status.'),
        ];
    }
}

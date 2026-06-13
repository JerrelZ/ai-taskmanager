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

#[Description('Get a customer\'s revenue and commission over a date range from the project\'s external support API, bucketed by hour, day or month.')]
class CustomerRevenueTool extends Tool
{
    public function handle(Request $request, ExternalProjectApi $api): Response|ResponseFactory
    {
        $validated = $request->validate([
            'project' => ['required', 'string', 'max:100'],
            'user_id' => ['required', 'string', 'max:64'],
            'from' => ['required', 'date'],
            'to' => ['required', 'date'],
            'granularity' => ['sometimes', 'string', 'in:hour,day,month'],
        ]);

        $project = ProjectResolver::find($validated['project']);
        $account = $project !== null ? ProjectResolver::account($project) : null;

        if ($account === null || ! $api->configured($account)) {
            return Response::error('No support API configured for this project.');
        }

        try {
            $revenue = $api->revenue(
                $account,
                $validated['user_id'],
                $validated['from'],
                $validated['to'],
                $validated['granularity'] ?? 'day',
            );
        } catch (\Throwable $e) {
            return Response::error('API request failed: '.Str::limit($e->getMessage(), 300));
        }

        return Response::structured([
            'summary' => "Revenue for user {$validated['user_id']} from {$validated['from']} to {$validated['to']}.",
            'user_id' => $validated['user_id'],
            'data' => $revenue,
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
            'from' => $schema->string()->description('Start date (YYYY-MM-DD).')->required(),
            'to' => $schema->string()->description('End date (YYYY-MM-DD).')->required(),
            'granularity' => $schema->string()->enum(['hour', 'day', 'month'])->description('Bucket size. Defaults to day.')->default('day'),
        ];
    }
}

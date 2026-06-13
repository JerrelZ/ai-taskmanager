<?php

namespace App\Mcp\Tools;

use App\Services\Email\ExternalProjectDb;
use App\Support\ProjectResolver;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tool;

#[Description('Run a single READ-ONLY SQL statement (SELECT/SHOW/DESCRIBE/EXPLAIN) against a project\'s external customer database. Writes are rejected. Discover the schema first with "SHOW TABLES" and "DESCRIBE <table>".')]
class QueryProjectDatabaseTool extends Tool
{
    /** Hard cap on rows returned to the client to keep responses bounded. */
    private const MAX_ROWS = 200;

    public function handle(Request $request, ExternalProjectDb $externalDb): Response|ResponseFactory
    {
        $validated = $request->validate([
            'project' => ['required', 'string', 'max:100'],
            'sql' => ['required', 'string', 'max:4000'],
            'bindings' => ['sometimes', 'array'],
        ], [
            'project.required' => 'Specify the project key, name, or id (e.g. "REV" or "Revboost").',
            'sql.required' => 'Provide a single read-only SQL statement (SELECT, SHOW, DESCRIBE or EXPLAIN).',
        ]);

        $project = ProjectResolver::find($validated['project']);

        if ($project === null) {
            return Response::error("No project found matching \"{$validated['project']}\". Call \"list-projects\" first.");
        }

        $account = ProjectResolver::account($project);

        if ($account === null || blank($account->external_db_dsn)) {
            return Response::error("Project \"{$project->name}\" has no external database configured.");
        }

        try {
            $rows = $externalDb->select($account, $validated['sql'], $validated['bindings'] ?? []);
        } catch (InvalidArgumentException $e) {
            // Read-only / multiple-statement violation: actionable for the model.
            return Response::error($e->getMessage());
        } catch (\Throwable $e) {
            return Response::error('Query failed: '.Str::limit($e->getMessage(), 300));
        }

        $total = count($rows);
        $clipped = array_slice($rows, 0, self::MAX_ROWS);
        $data = array_map(fn ($row): array => (array) $row, $clipped);

        $summary = $total === 0
            ? 'Query succeeded — 0 rows.'
            : "Query succeeded — {$total} row(s)"
                .($total > self::MAX_ROWS ? ' (showing first '.self::MAX_ROWS.').' : '.');

        return Response::structured([
            'summary' => $summary,
            'project' => $project->key ?? $project->name,
            'row_count' => $total,
            'truncated' => $total > self::MAX_ROWS,
            'rows' => $data,
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
            'sql' => $schema->string()
                ->description('A single read-only statement: SELECT, SHOW, DESCRIBE or EXPLAIN. No writes, no stacked statements.')
                ->required(),
            'bindings' => $schema->array()
                ->description('Optional positional bindings for "?" placeholders in the SQL, in order.'),
        ];
    }
}

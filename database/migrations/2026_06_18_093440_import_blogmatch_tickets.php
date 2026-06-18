<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    private const CLIENT_NAME = 'Blogmatch';

    private const PROJECT_KEY = 'BLO';

    /**
     * Map Linear statuses onto our TaskStatus enum values.
     */
    private const STATUS_MAP = [
        'Backlog' => 'backlog',
        'Todo' => 'todo',
        'In Progress' => 'in_progress',
        'Done' => 'done',
        'Canceled' => 'canceled',
    ];

    /**
     * Map Linear priorities onto our TaskPriority enum values.
     */
    private const PRIORITY_MAP = [
        'Urgent' => 'urgent',
        'High' => 'high',
        'Medium' => 'medium',
        'Low' => 'low',
    ];

    /**
     * Seed the Blogmatch client, its project and the exported tickets.
     *
     * Guarded so it is a no-op on a fresh database (e.g. the test suite, which
     * has no workspace yet): the data only lands in an install that already has
     * a workspace to attach it to, and never twice.
     */
    public function up(): void
    {
        $workspaceId = DB::table('workspaces')->min('id');

        if ($workspaceId === null) {
            return;
        }

        if (DB::table('clients')->where('workspace_id', $workspaceId)->where('name', self::CLIENT_NAME)->exists()) {
            return;
        }

        $rows = $this->readRows();

        if ($rows === []) {
            return;
        }

        $now = now();

        $clientId = DB::table('clients')->insertGetId([
            'workspace_id' => $workspaceId,
            'name' => self::CLIENT_NAME,
            'color' => 'blue',
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $projectId = DB::table('projects')->insertGetId([
            'workspace_id' => $workspaceId,
            'client_id' => $clientId,
            'name' => self::CLIENT_NAME,
            'key' => $this->uniqueProjectKey(),
            'color' => 'blue',
            'status' => 'active',
            'position' => (int) DB::table('projects')->where('workspace_id', $workspaceId)->max('position') + 1,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $this->insertTasks($rows, $projectId);
        $this->linkParents($rows, $projectId);
    }

    /**
     * @param  list<array{number: int|null, parent: int|null, attributes: array<string, mixed>}>  $rows
     */
    private function insertTasks(array $rows, int $projectId): void
    {
        $records = [];

        foreach ($rows as $position => $row) {
            $records[] = [
                'project_id' => $projectId,
                'number' => $row['number'],
                'parent_id' => null,
                'position' => $position,
                'rank' => $position,
                ...$row['attributes'],
            ];
        }

        foreach (array_chunk($records, 200) as $chunk) {
            DB::table('tasks')->insert($chunk);
        }
    }

    /**
     * Second pass: now that every ticket exists, wire up the parent/subtask
     * links by matching the original Linear numbers within this project.
     *
     * @param  list<array{number: int|null, parent: int|null, attributes: array<string, mixed>}>  $rows
     */
    private function linkParents(array $rows, int $projectId): void
    {
        $idByNumber = DB::table('tasks')
            ->where('project_id', $projectId)
            ->pluck('id', 'number');

        foreach ($rows as $row) {
            if ($row['parent'] === null || $row['number'] === null) {
                continue;
            }

            $parentId = $idByNumber[$row['parent']] ?? null;
            $childId = $idByNumber[$row['number']] ?? null;

            if ($parentId !== null && $childId !== null) {
                DB::table('tasks')->where('id', $childId)->update(['parent_id' => $parentId]);
            }
        }
    }

    /**
     * Read and normalise every ticket from the CSV export.
     *
     * @return list<array{number: int|null, parent: int|null, attributes: array<string, mixed>}>
     */
    private function readRows(): array
    {
        $path = database_path('data/blogmatch-tasks.csv');

        if (! is_file($path) || ($handle = fopen($path, 'r')) === false) {
            return [];
        }

        $header = fgetcsv($handle, 0, ',', '"', '\\');

        if (! is_array($header)) {
            fclose($handle);

            return [];
        }

        $header = array_map(fn ($value) => (string) $value, $header);
        $rows = [];

        while (($data = fgetcsv($handle, 0, ',', '"', '\\')) !== false) {
            /** @var array<string, string> $row */
            $row = array_combine($header, $data);

            $title = trim($row['Title'] ?? '');
            $description = trim($row['Description'] ?? '');
            $createdAt = $this->parseDate($row['Created'] ?? '') ?? now();

            $rows[] = [
                'number' => $this->ticketNumber($row['ID'] ?? ''),
                'parent' => $this->ticketNumber($row['Parent issue'] ?? ''),
                'attributes' => [
                    'title' => $title !== '' ? $title : 'Zonder titel',
                    'description' => $description !== '' ? $description : null,
                    'status' => self::STATUS_MAP[$row['Status'] ?? ''] ?? 'backlog',
                    'priority' => self::PRIORITY_MAP[$row['Priority'] ?? ''] ?? 'none',
                    'due_date' => $this->parseDate($row['Due Date'] ?? '')?->toDateString(),
                    'created_at' => $createdAt,
                    'updated_at' => $this->parseDate($row['Updated'] ?? '') ?? $createdAt,
                ],
            ];
        }

        fclose($handle);

        return $rows;
    }

    /**
     * "BLO-42" -> 42, empty/garbage -> null.
     */
    private function ticketNumber(string $id): ?int
    {
        return preg_match('/(\d+)$/', trim($id), $matches) ? (int) $matches[1] : null;
    }

    /**
     * Parse a Linear timestamp like
     * "Tue Jul 08 2025 07:02:13 GMT+0000 (GMT+00:00)".
     */
    private function parseDate(string $value): ?Carbon
    {
        $value = trim($value);

        if ($value === '') {
            return null;
        }

        $value = trim((string) preg_replace('/\s*\(.*\)$/', '', $value));

        try {
            return Carbon::parse($value);
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * "BLO", or "BLO2" etc. when that key is already taken.
     */
    private function uniqueProjectKey(): string
    {
        $key = self::PROJECT_KEY;
        $suffix = 1;

        while (DB::table('projects')->where('key', $key)->exists()) {
            $key = self::PROJECT_KEY.$suffix;
            $suffix++;
        }

        return $key;
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        $projectIds = DB::table('projects')
            ->join('clients', 'clients.id', '=', 'projects.client_id')
            ->where('clients.name', self::CLIENT_NAME)
            ->pluck('projects.id');

        DB::table('tasks')->whereIn('project_id', $projectIds)->delete();
        DB::table('projects')->whereIn('id', $projectIds)->delete();
        DB::table('clients')->where('name', self::CLIENT_NAME)->delete();
    }
};

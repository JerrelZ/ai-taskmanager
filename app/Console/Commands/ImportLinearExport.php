<?php

namespace App\Console\Commands;

use App\Enums\UserRole;
use App\Models\Task;
use App\Models\User;
use App\Services\AttachmentService;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

#[Signature('linear:import
    {file? : Path to the Linear CSV export}
    {--owner=jerrel@zendos.nl : Email of the workspace owner the import lands in}
    {--create-users : Create missing Creator/Assignee users and print their generated passwords}
    {--relink : Backfill creator/assignee on already-imported tickets (matched by Linear id), creating missing users}
    {--no-attachments : Skip downloading ticket attachments}')]
#[Description('Import a Linear CSV export into the owner\'s workspace: one client + project per team, with parent links and attachments. Non-destructive and idempotent per team.')]
class ImportLinearExport extends Command
{
    /**
     * Map a Linear team onto the client name and project key it imports into.
     * Unknown teams fall back to the team name and a key derived from it.
     *
     * @var array<string, array{client: string, key: string}>
     */
    private const TEAM_MAP = [
        'Blogmatchers' => ['client' => 'Blogmatch', 'key' => 'BLO'],
        'RevBoost' => ['client' => 'RevBoost', 'key' => 'REV'],
        'BCC-V2' => ['client' => 'BCC', 'key' => 'BCC'],
    ];

    /**
     * Map Linear statuses onto our TaskStatus enum values.
     *
     * @var array<string, string>
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
     *
     * @var array<string, string>
     */
    private const PRIORITY_MAP = [
        'Urgent' => 'urgent',
        'High' => 'high',
        'Medium' => 'medium',
        'Low' => 'low',
    ];

    /**
     * The workspace every imported record lands in.
     */
    private int $workspaceId;

    /**
     * The owner downloaded attachments are attributed to.
     */
    private User $owner;

    /**
     * Resolved user id per (lower-cased) Creator/Assignee email. Empty unless
     * --create-users is set, in which case tickets are attributed to their
     * author and assignee.
     *
     * @var array<string, int>
     */
    private array $userIdByEmail = [];

    public function handle(AttachmentService $attachments): int
    {
        $path = $this->argument('file') ?? $this->latestExport();

        if ($path === null || ! is_file($path)) {
            $this->error('CSV niet gevonden. Geef het pad mee als argument.');

            return self::FAILURE;
        }

        $owner = User::firstWhere('email', $this->option('owner'));

        if ($owner === null || $owner->workspace_id === null) {
            $this->error("Eigenaar {$this->option('owner')} of diens workspace bestaat niet. Registreer eerst en draai dit dan opnieuw.");

            return self::FAILURE;
        }

        $this->owner = $owner;
        $this->workspaceId = $owner->workspace_id;

        $rowsByTeam = $this->readRows($path);

        if ($rowsByTeam === []) {
            $this->error('Geen rijen gevonden in de CSV.');

            return self::FAILURE;
        }

        // Relink mode: attribute already-imported tickets without re-importing.
        // Used when the first import ran without --create-users.
        if ($this->option('relink')) {
            $createdUsers = $this->resolveUsersFromExport($rowsByTeam);
            $linked = $this->relinkTasks($rowsByTeam);

            $this->info("Gekoppeld: {$linked} ticket(s) bijgewerkt met creator/assignee.");
            $this->reportCreatedUsers($createdUsers);

            return self::SUCCESS;
        }

        $createdUsers = $this->option('create-users')
            ? $this->resolveUsersFromExport($rowsByTeam)
            : [];

        foreach ($rowsByTeam as $team => $rows) {
            $this->importTeam((string) $team, $rows, $attachments);
        }

        $this->reportCreatedUsers($createdUsers);

        return self::SUCCESS;
    }

    /**
     * Find or create a user for every Creator/Assignee email in the export,
     * linking each into the owner's workspace, and record the email/id map used
     * to attribute tickets. Returns the freshly created users together with the
     * random password generated for them, so the operator can hand these out.
     *
     * Existing users keep their password; only new members get a generated one.
     *
     * @param  array<string, list<array{number: int|null, linear_id: string|null, parent: int|null, creator: string, assignee: string, raw_description: string, attributes: array<string, mixed>}>>  $rowsByTeam
     * @return list<array{email: string, password: string}>
     */
    private function resolveUsersFromExport(array $rowsByTeam): array
    {
        $emails = [];

        foreach ($rowsByTeam as $rows) {
            foreach ($rows as $row) {
                foreach ([$row['creator'], $row['assignee']] as $email) {
                    if ($email !== '' && str_contains($email, '@')) {
                        $emails[$email] = true;
                    }
                }
            }
        }

        $created = [];

        foreach (array_keys($emails) as $email) {
            $user = User::firstWhere('email', $email);

            if ($user === null) {
                $password = Str::password(16);

                $user = User::create([
                    'workspace_id' => $this->workspaceId,
                    'name' => $this->nameFromEmail($email),
                    'email' => $email,
                    'password' => $password,
                    'role' => UserRole::Member,
                    'email_verified_at' => now(),
                ]);

                $created[] = ['email' => $email, 'password' => $password];
            }

            $user->workspaces()->syncWithoutDetaching([$this->workspaceId]);

            $this->userIdByEmail[$email] = $user->id;
        }

        return $created;
    }

    /**
     * Print the generated passwords for newly created users as a table, so the
     * operator can share each colleague their first-login credentials.
     *
     * @param  list<array{email: string, password: string}>  $created
     */
    private function reportCreatedUsers(array $created): void
    {
        if ($created === []) {
            return;
        }

        $this->newLine();
        $this->info('Nieuwe gebruikers aangemaakt — deel deze wachtwoorden:');
        $this->table(
            ['E-mail', 'Wachtwoord'],
            array_map(fn (array $user) => [$user['email'], $user['password']], $created),
        );
    }

    /**
     * A readable display name derived from an email local part
     * ("jan.de.vries@x.nl" -> "Jan De Vries").
     */
    private function nameFromEmail(string $email): string
    {
        $local = (string) preg_replace('/[._-]+/', ' ', Str::before($email, '@'));
        $name = Str::title(trim($local));

        return $name !== '' ? $name : $email;
    }

    /**
     * Import one Linear team into its own client + project. Skips the team when
     * its client already exists in the workspace, so re-runs are safe.
     *
     * @param  list<array{number: int|null, linear_id: string|null, parent: int|null, creator: string, assignee: string, raw_description: string, attributes: array<string, mixed>}>  $rows
     */
    private function importTeam(string $team, array $rows, AttachmentService $attachments): void
    {
        $mapping = self::TEAM_MAP[$team] ?? null;
        $clientName = $mapping['client'] ?? $team;
        $baseKey = $mapping['key'] ?? $this->deriveKey($team);

        $exists = DB::table('clients')
            ->where('workspace_id', $this->workspaceId)
            ->where('name', $clientName)
            ->exists();

        if ($exists) {
            $this->warn("Team '{$team}': client '{$clientName}' bestaat al — overgeslagen.");

            return;
        }

        $now = now();

        $clientId = DB::table('clients')->insertGetId([
            'workspace_id' => $this->workspaceId,
            'name' => $clientName,
            'color' => 'blue',
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $projectId = DB::table('projects')->insertGetId([
            'workspace_id' => $this->workspaceId,
            'client_id' => $clientId,
            'name' => $clientName,
            'key' => $this->uniqueProjectKey($baseKey),
            'color' => 'blue',
            'status' => 'active',
            'position' => (int) DB::table('projects')->where('workspace_id', $this->workspaceId)->max('position') + 1,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $this->insertTasks($rows, $projectId);
        $this->linkParents($rows, $projectId);

        $attachmentCount = $this->option('no-attachments')
            ? 0
            : $this->downloadAttachments($rows, $projectId, $attachments);

        $this->info("Team '{$team}' → client '{$clientName}': ".count($rows)." tickets, {$attachmentCount} bijlagen.");
    }

    /**
     * Bulk-insert the tickets. We use the query builder so no model events fire
     * (the import must not trigger the per-task AI-readiness jobs). Creator and
     * assignee are attributed only when --create-users resolved their emails to
     * users; otherwise both stay null.
     *
     * @param  list<array{number: int|null, linear_id: string|null, parent: int|null, creator: string, assignee: string, raw_description: string, attributes: array<string, mixed>}>  $rows
     */
    private function insertTasks(array $rows, int $projectId): void
    {
        $records = [];

        foreach (array_values($rows) as $position => $row) {
            $records[] = [
                'project_id' => $projectId,
                'number' => $row['number'],
                'linear_id' => $row['linear_id'],
                'parent_id' => null,
                'position' => $position,
                'created_by' => $this->userIdByEmail[$row['creator']] ?? null,
                'assignee_id' => $this->userIdByEmail[$row['assignee']] ?? null,
                ...$row['attributes'],
            ];
        }

        foreach (array_chunk($records, 200) as $chunk) {
            DB::table('tasks')->insert($chunk);
        }
    }

    /**
     * Backfill creator/assignee on already-imported tickets, matching each export
     * row to its existing task by Linear id. Lets an import that first ran without
     * --create-users be attributed afterwards. Only sets a column when its email
     * resolved to a user, so unknown creators/assignees stay untouched. Returns
     * how many tickets were updated.
     *
     * @param  array<string, list<array{number: int|null, linear_id: string|null, parent: int|null, creator: string, assignee: string, raw_description: string, attributes: array<string, mixed>}>>  $rowsByTeam
     */
    private function relinkTasks(array $rowsByTeam): int
    {
        $updated = 0;

        foreach ($rowsByTeam as $rows) {
            foreach ($rows as $row) {
                if ($row['linear_id'] === null) {
                    continue;
                }

                $attributes = array_filter([
                    'created_by' => $this->userIdByEmail[$row['creator']] ?? null,
                    'assignee_id' => $this->userIdByEmail[$row['assignee']] ?? null,
                ], fn ($value): bool => $value !== null);

                if ($attributes === []) {
                    continue;
                }

                $updated += DB::table('tasks')->where('linear_id', $row['linear_id'])->update($attributes);
            }
        }

        return $updated;
    }

    /**
     * Second pass: wire up parent/subtask links by matching the original Linear
     * numbers within this project.
     *
     * @param  list<array{number: int|null, linear_id: string|null, parent: int|null, creator: string, assignee: string, raw_description: string, attributes: array<string, mixed>}>  $rows
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
     * Download every Linear-hosted attachment referenced in a ticket description
     * and link it to the task. Returns how many were stored.
     *
     * @param  list<array{number: int|null, linear_id: string|null, parent: int|null, creator: string, assignee: string, raw_description: string, attributes: array<string, mixed>}>  $rows
     */
    private function downloadAttachments(array $rows, int $projectId, AttachmentService $attachments): int
    {
        $idByNumber = DB::table('tasks')
            ->where('project_id', $projectId)
            ->pluck('id', 'number');

        $stored = 0;

        foreach ($rows as $row) {
            if ($row['number'] === null) {
                continue;
            }

            $taskId = $idByNumber[$row['number']] ?? null;

            preg_match_all('/(!?)\[([^\]]*)\]\((https:\/\/uploads\.linear\.app\/[^)\s]+)\)/', $row['raw_description'], $matches, PREG_SET_ORDER);

            if ($taskId === null || $matches === []) {
                continue;
            }

            $task = Task::find($taskId);

            if ($task === null) {
                continue;
            }

            foreach ($matches as $match) {
                $filename = $this->attachmentFilename($match[2], $match[3]);

                try {
                    $response = Http::timeout(30)->get($match[3]);

                    if (! $response->successful()) {
                        $this->warn("{$task->identifier()}: bijlage {$filename} overslaan (HTTP {$response->status()}).");

                        continue;
                    }

                    $attachments->storeRaw(
                        $response->body(),
                        $filename,
                        $response->header('Content-Type') ?: null,
                        $task,
                        $this->owner,
                    );

                    $stored++;
                } catch (\Throwable $e) {
                    $this->warn("{$task->identifier()}: bijlage {$filename} mislukt ({$e->getMessage()}).");
                }
            }
        }

        return $stored;
    }

    /**
     * Read and normalise every ticket, grouped by Linear team.
     *
     * @return array<string, list<array{number: int|null, linear_id: string|null, parent: int|null, creator: string, assignee: string, raw_description: string, attributes: array<string, mixed>}>>
     */
    private function readRows(string $path): array
    {
        $handle = fopen($path, 'r');

        if ($handle === false) {
            return [];
        }

        $header = fgetcsv($handle, 0, ',', '"', '\\');

        if (! is_array($header)) {
            fclose($handle);

            return [];
        }

        $header = array_map(fn ($value) => (string) $value, $header);
        $byTeam = [];

        while (($data = fgetcsv($handle, 0, ',', '"', '\\')) !== false) {
            /** @var array<string, string> $row */
            $row = array_combine($header, $data);

            $team = trim($row['Team'] ?? '');

            if ($team === '') {
                continue;
            }

            $title = trim($row['Title'] ?? '');
            $rawDescription = (string) ($row['Description'] ?? '');
            $createdAt = $this->parseDate($row['Created'] ?? '') ?? now();

            $byTeam[$team][] = [
                'number' => $this->ticketNumber($row['ID'] ?? ''),
                'linear_id' => trim($row['ID'] ?? '') ?: null,
                'parent' => $this->ticketNumber($row['Parent issue'] ?? ''),
                'creator' => Str::lower(trim($row['Creator'] ?? '')),
                'assignee' => Str::lower(trim($row['Assignee'] ?? '')),
                'raw_description' => $rawDescription,
                'attributes' => [
                    'title' => $title !== '' ? $title : 'Zonder titel',
                    'description' => trim($rawDescription) !== '' ? $this->descriptionToHtml($rawDescription) : null,
                    'status' => self::STATUS_MAP[$row['Status'] ?? ''] ?? 'backlog',
                    'priority' => self::PRIORITY_MAP[$row['Priority'] ?? ''] ?? 'none',
                    'due_date' => $this->parseDate($row['Due Date'] ?? '')?->toDateString(),
                    'created_at' => $createdAt,
                    'updated_at' => $this->parseDate($row['Updated'] ?? '') ?? $createdAt,
                ],
            ];
        }

        fclose($handle);

        return $byTeam;
    }

    /**
     * Newest "Export ... .csv" file in the project root, if any.
     */
    private function latestExport(): ?string
    {
        $files = glob(base_path('Export*.csv')) ?: [];

        return $files[0] ?? null;
    }

    /**
     * The Linear export stores descriptions as Markdown. The task detail renders
     * the field as raw HTML, so convert it once on import — otherwise every
     * newline collapses and the text runs together.
     */
    private function descriptionToHtml(string $description): string
    {
        return trim(Str::markdown($description));
    }

    private function attachmentFilename(string $label, string $url): string
    {
        $label = trim($label);

        if ($label !== '' && str_contains($label, '.')) {
            return $label;
        }

        $base = $label !== '' ? Str::slug($label) : 'bijlage-'.substr(md5($url), 0, 8);

        return $base.'.bin';
    }

    /**
     * "BLO-42" -> 42, empty/garbage -> null.
     */
    private function ticketNumber(string $id): ?int
    {
        return preg_match('/(\d+)$/', trim($id), $matches) ? (int) $matches[1] : null;
    }

    /**
     * A three-letter, uppercased key derived from the team name.
     */
    private function deriveKey(string $team): string
    {
        $alnum = preg_replace('/[^A-Za-z0-9]/', '', $team);

        return Str::upper(Str::substr($alnum !== '' ? $alnum : 'PRJ', 0, 3));
    }

    /**
     * The given key, suffixed with a number when it is already taken.
     */
    private function uniqueProjectKey(string $base): string
    {
        $base = $base !== '' ? $base : 'PRJ';
        $key = $base;
        $suffix = 1;

        while (DB::table('projects')->where('key', $key)->exists()) {
            $key = $base.$suffix;
            $suffix++;
        }

        return $key;
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
        } catch (\Throwable) {
            return null;
        }
    }
}

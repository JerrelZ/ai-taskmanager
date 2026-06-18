<?php

namespace App\Console\Commands;

use App\Enums\ProjectStatus;
use App\Enums\TaskPriority;
use App\Enums\TaskStatus;
use App\Enums\UserRole;
use App\Models\Project;
use App\Models\Task;
use App\Models\User;
use App\Models\Workspace;
use App\Services\AttachmentService;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

#[Signature('linear:import
    {file? : Path to the Linear CSV export}
    {--id-prefix=REVBOOS : Only import tickets whose ID contains this prefix}
    {--project-key=REVBOOS : Key for the project the tickets land in}
    {--project-name=RevBoost : Name for the project the tickets land in}
    {--admin-email=jerrel@zendos.nl : Email that becomes user 1 (admin)}
    {--password=password : Plain password assigned to every created user}
    {--no-attachments : Skip downloading ticket attachments}
    {--force : Wipe existing data without confirmation}')]
#[Description('Import open (non-completed) RevBoost tickets from a Linear CSV export, creating users, the project and downloading attachments')]
class ImportLinearExport extends Command
{
    /**
     * Domain tables cleared before a fresh import (FK order does not matter
     * because checks are disabled during the wipe).
     *
     * @var list<string>
     */
    private const WIPE_TABLES = [
        'attachments', 'label_task', 'labels', 'comments', 'activities', 'tasks',
        'projects', 'clients', 'conversation_user', 'conversations', 'messages',
        'email_contact_links', 'email_messages', 'email_threads', 'email_folders',
        'email_accounts', 'reply_templates', 'claude_code_runs', 'notifications', 'users',
        'workspaces',
    ];

    /**
     * The workspace every imported record belongs to.
     */
    private Workspace $workspace;

    /**
     * Statuses considered "completed" and therefore skipped (matches the app's
     * own Task::isComplete() definition).
     *
     * @var list<string>
     */
    private const COMPLETED_STATUSES = ['Done', 'Canceled'];

    /**
     * Cache of created/looked-up users, keyed by lowercased email.
     *
     * @var array<string, User>
     */
    private array $users = [];

    public function handle(AttachmentService $attachments): int
    {
        $path = $this->argument('file') ?? $this->latestExport();

        if ($path === null || ! is_file($path)) {
            $this->error('CSV niet gevonden. Geef het pad mee als argument.');

            return self::FAILURE;
        }

        if (! $this->option('force') && ! $this->confirm('Dit verwijdert ALLE bestaande data (users, projecten, tickets, e-mail, chat). Doorgaan?')) {
            return self::FAILURE;
        }

        $this->wipe();

        $this->workspace = Workspace::create(['name' => $this->option('project-name').' werkruimte']);

        $project = $this->createProject();
        $admin = $this->resolveUser($this->option('admin-email'), UserRole::Admin);

        $rows = $this->readMatchingRows($path);
        $this->info(count($rows).' open '.$this->option('id-prefix').'-tickets gevonden.');

        $imported = 0;
        $attachmentCount = 0;

        $this->withProgressBar($rows, function (array $row) use ($project, $attachments, &$imported, &$attachmentCount): void {
            $task = $this->importTask($row, $project, $imported);
            $imported++;

            if (! $this->option('no-attachments')) {
                $attachmentCount += $this->importAttachments($task, $row['Description'], $attachments);
            }
        });

        $this->newLine(2);
        $this->info("Klaar: {$imported} tickets, ".count($this->users)." users, {$attachmentCount} bijlagen.");
        $this->line("Admin: {$admin->email} (id {$admin->id}), wachtwoord '{$this->option('password')}'.");

        return self::SUCCESS;
    }

    /**
     * Newest "Export ... .csv" file in the project root, if any.
     */
    private function latestExport(): ?string
    {
        $files = glob(base_path('Export*.csv')) ?: [];

        return $files[0] ?? null;
    }

    private function wipe(): void
    {
        Schema::disableForeignKeyConstraints();

        foreach (self::WIPE_TABLES as $table) {
            if (Schema::hasTable($table)) {
                DB::table($table)->truncate();
            }
        }

        Schema::enableForeignKeyConstraints();
    }

    private function createProject(): Project
    {
        return Project::create([
            'workspace_id' => $this->workspace->id,
            'name' => $this->option('project-name'),
            'key' => $this->option('project-key'),
            'color' => 'blue',
            'status' => ProjectStatus::Active,
            'position' => 0,
        ]);
    }

    /**
     * Read, filter and column-map the CSV into associative rows.
     *
     * @return list<array<string, string>>
     */
    private function readMatchingRows(string $path): array
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
        $prefix = $this->option('id-prefix');
        $rows = [];

        while (($data = fgetcsv($handle, 0, ',', '"', '\\')) !== false) {
            /** @var array<string, string> $row */
            $row = array_combine($header, $data);

            if (! str_contains($row['ID'] ?? '', $prefix)) {
                continue;
            }

            if (in_array($row['Status'] ?? '', self::COMPLETED_STATUSES, true)) {
                continue;
            }

            $rows[] = $row;
        }

        fclose($handle);

        return $rows;
    }

    /**
     * @param  array<string, string>  $row
     */
    private function importTask(array $row, Project $project, int $position): Task
    {
        $task = new Task([
            'project_id' => $project->id,
            'number' => $this->ticketNumber($row['ID']),
            'title' => $row['Title'] !== '' ? $row['Title'] : 'Zonder titel',
            'description' => $row['Description'] !== '' ? $row['Description'] : null,
            'status' => $this->mapStatus($row['Status']),
            'priority' => $this->mapPriority($row['Priority']),
            'assignee_id' => $this->resolveUser($row['Assignee'], UserRole::Member)?->id,
            'created_by' => $this->resolveUser($row['Creator'], UserRole::Member)?->id,
            'due_date' => $this->parseDate($row['Due Date'] ?? ''),
            'position' => $position,
            'rank' => $position,
        ]);

        $createdAt = $this->parseDate($row['Created'] ?? '') ?? now();

        $task->forceFill([
            'created_at' => $createdAt,
            'updated_at' => $this->parseDate($row['Updated'] ?? '') ?? $createdAt,
        ])->save();

        return $task;
    }

    /**
     * Download every Linear-hosted attachment referenced in the description and
     * link it to the task. Returns how many were stored.
     */
    private function importAttachments(Task $task, string $description, AttachmentService $attachments): int
    {
        preg_match_all('/(!?)\[([^\]]*)\]\((https:\/\/uploads\.linear\.app\/[^)\s]+)\)/', $description, $matches, PREG_SET_ORDER);

        $stored = 0;

        foreach ($matches as $match) {
            $filename = $this->attachmentFilename($match[2], $match[3]);
            $url = $match[3];

            try {
                $response = Http::timeout(30)->get($url);

                if (! $response->successful()) {
                    $this->warn("\n{$task->identifier()}: bijlage {$filename} overslaan (HTTP {$response->status()}).");

                    continue;
                }

                $attachments->storeRaw(
                    $response->body(),
                    $filename,
                    $response->header('Content-Type') ?: null,
                    $task,
                    $task->creator,
                );

                $stored++;
            } catch (\Throwable $e) {
                $this->warn("\n{$task->identifier()}: bijlage {$filename} mislukt ({$e->getMessage()}).");
            }
        }

        return $stored;
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
     * Find or create a user for the given email, caching by email.
     */
    private function resolveUser(?string $email, UserRole $role): ?User
    {
        $email = trim((string) $email);

        if ($email === '') {
            return null;
        }

        $key = Str::lower($email);

        return $this->users[$key] ??= User::firstOrCreate(
            ['email' => $email],
            [
                'workspace_id' => $this->workspace->id,
                'name' => $this->nameFromEmail($email),
                'password' => Hash::make($this->option('password')),
                'role' => $role,
            ],
        );
    }

    private function nameFromEmail(string $email): string
    {
        $local = Str::before($email, '@');

        return Str::of($local)->replace(['.', '_', '-'], ' ')->title()->toString();
    }

    private function ticketNumber(string $id): ?int
    {
        return preg_match('/(\d+)$/', $id, $m) ? (int) $m[1] : null;
    }

    private function mapStatus(string $status): TaskStatus
    {
        return match ($status) {
            'Backlog' => TaskStatus::Backlog,
            'Todo' => TaskStatus::Todo,
            'In Progress' => TaskStatus::InProgress,
            'Done' => TaskStatus::Done,
            'Canceled' => TaskStatus::Canceled,
            default => TaskStatus::Backlog,
        };
    }

    private function mapPriority(string $priority): TaskPriority
    {
        return match ($priority) {
            'Urgent' => TaskPriority::Urgent,
            'High' => TaskPriority::High,
            'Medium' => TaskPriority::Medium,
            'Low' => TaskPriority::Low,
            default => TaskPriority::None,
        };
    }

    /**
     * Parse a Linear timestamp like "Wed Sep 25 2024 08:57:00 GMT+0000 (GMT+00:00)".
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

<?php

namespace App\Services\Email;

use App\Models\EmailAccount;
use App\Models\EmailThread;
use App\Models\Project;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Str;

/**
 * Assembles a Markdown context blob shown beside an email thread, drawing on
 * three sources: existing app data, the project's git repository, and the
 * project's external (read-only) MySQL database. Each source is best-effort —
 * one failing source never breaks the panel.
 */
class EmailContextBuilder
{
    public function __construct(private readonly ExternalProjectDb $externalDb) {}

    public function build(EmailThread $thread): string
    {
        $thread->loadMissing(['project', 'account', 'messages']);

        $project = $thread->project;
        $senderEmail = $thread->messages->firstWhere('direction', 'inbound')?->from_email;

        $sections = array_filter([
            $this->appSection($thread, $project),
            $this->repoSection($project),
            $this->externalDbSection($thread->account, $senderEmail),
        ]);

        return implode("\n", $sections);
    }

    private function appSection(EmailThread $thread, Project $project): string
    {
        $lines = ['## Projectcontext'];
        $lines[] = "- **Project:** {$project->name}";

        if (filled($project->stack)) {
            $lines[] = "- **Stack:** {$project->stack}";
        }

        if (filled($project->context)) {
            $lines[] = "- **Conventies:** {$project->context}";
        }

        $openTasks = $project->tasks()
            ->actionable()
            ->latest('updated_at')
            ->limit(8)
            ->get();

        if ($openTasks->isNotEmpty()) {
            $lines[] = '';
            $lines[] = '### Open taken';
            foreach ($openTasks as $task) {
                $lines[] = "- {$task->identifier()} {$task->title} ({$task->status->label()})";
            }
        }

        return implode("\n", $lines)."\n";
    }

    private function repoSection(Project $project): ?string
    {
        if (blank($project->repo_path) || ! is_dir($project->repo_path.'/.git')) {
            return null;
        }

        try {
            $branch = trim($this->git($project->repo_path, ['rev-parse', '--abbrev-ref', 'HEAD']));
            $log = trim($this->git($project->repo_path, ['log', '--oneline', '-n', '15']));
        } catch (\Throwable) {
            return null;
        }

        $lines = ['## Repository'];
        $lines[] = "- **Pad:** {$project->repo_path}";

        if ($branch !== '') {
            $lines[] = "- **Branch:** {$branch}";
        }

        if ($log !== '') {
            $lines[] = '';
            $lines[] = '### Recente commits';
            $lines[] = '```';
            $lines[] = $log;
            $lines[] = '```';
        }

        return implode("\n", $lines)."\n";
    }

    private function externalDbSection(EmailAccount $account, ?string $senderEmail): ?string
    {
        if (blank($account->external_db_dsn)) {
            return null;
        }

        $lines = ['## Projectdatabase'];

        try {
            $tables = collect($this->externalDb->select($account, 'SHOW TABLES'))
                ->map(fn (object $row): string => (string) collect((array) $row)->first())
                ->all();

            $lines[] = '- **Tabellen:** '.(count($tables) > 0
                ? Str::limit(implode(', ', $tables), 300)
                : '(geen)');

            $matches = $senderEmail !== null
                ? $this->lookupSender($account, $senderEmail)
                : [];

            if ($matches !== []) {
                $lines[] = '';
                $lines[] = "### Gevonden voor {$senderEmail}";
                foreach ($matches as $match) {
                    $lines[] = "- **{$match['table']}**: ".Str::limit($match['row'], 200);
                }
            }
        } catch (\Throwable $e) {
            $lines[] = '- _Kon database niet lezen: '.Str::limit($e->getMessage(), 120).'_';
        }

        return implode("\n", $lines)."\n";
    }

    /**
     * Find rows whose email-like column matches the sender, across the schema.
     *
     * @return array<int, array{table: string, row: string}>
     */
    private function lookupSender(EmailAccount $account, string $senderEmail): array
    {
        $columns = $this->externalDb->select(
            $account,
            'SELECT TABLE_NAME, COLUMN_NAME FROM information_schema.COLUMNS '
                .'WHERE TABLE_SCHEMA = DATABASE() AND COLUMN_NAME LIKE ? LIMIT 5',
            ['%email%'],
        );

        $results = [];

        foreach ($columns as $column) {
            $table = (string) $column->TABLE_NAME;
            $col = (string) $column->COLUMN_NAME;

            // Identifiers cannot be bound; they come from information_schema, not user input.
            if (! preg_match('/^[A-Za-z0-9_]+$/', $table) || ! preg_match('/^[A-Za-z0-9_]+$/', $col)) {
                continue;
            }

            $rows = $this->externalDb->select(
                $account,
                "SELECT * FROM `{$table}` WHERE `{$col}` = ? LIMIT 1",
                [$senderEmail],
            );

            if ($rows !== []) {
                $results[] = ['table' => $table, 'row' => $this->summariseRow((array) $rows[0])];
            }

            if (count($results) >= 3) {
                break;
            }
        }

        return $results;
    }

    /**
     * @param  array<string, mixed>  $row
     */
    private function summariseRow(array $row): string
    {
        return collect($row)
            ->take(6)
            ->map(fn ($value, $key): string => "{$key}={$value}")
            ->implode(', ');
    }

    /**
     * @param  array<int, string>  $args
     */
    private function git(string $repoPath, array $args): string
    {
        $result = Process::path($repoPath)->run(array_merge(['git'], $args));

        return $result->successful() ? $result->output() : '';
    }
}

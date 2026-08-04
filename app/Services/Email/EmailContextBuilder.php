<?php

namespace App\Services\Email;

use App\Models\EmailAccount;
use App\Models\EmailContactLink;
use App\Models\EmailThread;
use App\Models\Project;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Str;

/**
 * Assembles a Markdown context blob shown beside an email thread, drawing on
 * three sources: existing app data, the project's git repository, and the
 * project's read-only support API. Each source is best-effort — one failing
 * source never breaks the panel.
 */
class EmailContextBuilder
{
    public function __construct(
        private readonly ExternalProjectApi $api,
        private readonly ContactLinkSuggester $linkSuggester,
    ) {}

    public function build(EmailThread $thread): string
    {
        $thread->loadMissing(['project', 'account', 'messages']);

        $project = $thread->project;
        $senderEmail = $thread->messages->firstWhere('direction', 'inbound')?->from_email;

        $sections = array_filter([
            $this->appSection($thread, $project),
            $this->repoSection($project),
            $this->supportApiSection($thread->account, $senderEmail),
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

    private function supportApiSection(EmailAccount $account, ?string $senderEmail): ?string
    {
        if (! $this->api->configured($account)) {
            return null;
        }

        $lines = ['## Klantgegevens (support-API)'];

        // A confirmed sender→record link is authoritative; use it instead of guessing.
        $link = $senderEmail !== null
            ? EmailContactLink::where('email_account_id', $account->id)->where('email', $senderEmail)->first()
            : null;

        try {
            if ($link !== null) {
                $resolved = $this->linkSuggester->resolve($link);

                $lines[] = "### Gekoppeld contact ({$link->external_resource})";

                if ($resolved !== null) {
                    $lines[] = "- **{$resolved['label']}**";
                    foreach (array_slice($resolved['fields'], 0, 10, true) as $key => $value) {
                        $lines[] = "  - {$key}: ".Str::limit((string) $value, 120);
                    }
                } else {
                    $lines[] = "- _Gekoppeld record ({$link->external_resource} #{$link->external_id}) niet gevonden._";
                }

                return implode("\n", $lines)."\n";
            }

            $matches = $senderEmail !== null
                ? $this->linkSuggester->suggest($account, $senderEmail)
                : [];

            if ($matches === []) {
                $lines[] = '- _Geen klant gevonden voor '.($senderEmail ?? 'deze afzender').'._';
            } else {
                $lines[] = "### Mogelijke matches voor {$senderEmail}";
                foreach ($matches as $match) {
                    $lines[] = "- **{$match['label']}** ({$match['resource']} #{$match['id']}): ".Str::limit($match['preview'], 200);
                }
            }
        } catch (\Throwable $e) {
            $lines[] = '- _Kon de support-API niet lezen: '.Str::limit($e->getMessage(), 120).'_';
        }

        return implode("\n", $lines)."\n";
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

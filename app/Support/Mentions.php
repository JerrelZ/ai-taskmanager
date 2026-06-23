<?php

namespace App\Support;

use App\Models\Project;
use App\Models\Task;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

class Mentions
{
    /** @var Collection<int, User>|null */
    private static ?Collection $cachedUsers = null;

    /**
     * Render plain-text message/comment input as safe HTML: escape, linkify URLs,
     * highlight user mentions and — within a project context — turn #123 ticket
     * references into chips that deep-link to the task.
     *
     * @param  Collection<int, User>|null  $users
     */
    public static function render(string $text, ?Collection $users = null, ?Project $project = null): string
    {
        $names = self::mentionNames($users ??= self::users());

        $safe = self::highlightMentions(self::linkifyUrls(e($text)), $names);

        if ($project !== null) {
            $safe = self::renderTicketReferences($safe, $project);
        }

        return nl2br($safe);
    }

    /**
     * Render a comment for display. Comments written in the rich editor arrive as
     * (already-sanitised) HTML — escaping that would show raw tags, so decorate
     * only the text between tags. Legacy plain-text comments (no markup) fall back
     * to the escaping {@see self::render()} path.
     *
     * @param  Collection<int, User>|null  $users
     */
    public static function renderComment(string $body, ?Collection $users = null, ?Project $project = null): string
    {
        if (! preg_match('/<(?:p|br|ul|ol|li|img|strong|em|b|i|u|a|h[1-6]|blockquote|pre|code)\b/i', $body)) {
            return self::render($body, $users, $project);
        }

        $names = self::mentionNames($users ??= self::users());

        // Split into tags and the text between them, then decorate the text
        // segments only — never the markup — so URLs/mentions inside an <a href>
        // or <img src> are left untouched.
        $parts = preg_split('/(<[^>]+>)/', $body, -1, PREG_SPLIT_DELIM_CAPTURE) ?: [$body];

        foreach ($parts as $i => $part) {
            if ($part === '' || $part[0] === '<') {
                continue;
            }

            $parts[$i] = self::highlightMentions(self::linkifyUrls($part), $names);
        }

        $html = implode('', $parts);

        if ($project !== null) {
            $html = self::renderTicketReferences($html, $project);
        }

        return $html;
    }

    /**
     * The distinct @-mention targets (full and first names), regex-quoted and
     * longest-first so "@Sanne de Vries" wins over "@Sanne".
     *
     * @param  Collection<int, User>  $users
     * @return Collection<int, string>
     */
    private static function mentionNames(Collection $users): Collection
    {
        return $users
            ->flatMap(fn (User $user) => [$user->name, Str::before($user->name, ' ')])
            ->filter()
            ->unique()
            ->sortByDesc(fn (string $name) => strlen($name))
            ->map(fn (string $name) => preg_quote(e($name), '/'))
            ->values();
    }

    private static function linkifyUrls(string $html): string
    {
        return (string) preg_replace(
            '~(https?://[^\s<]+)~',
            '<a href="$1" target="_blank" rel="noopener" class="text-brand-500 underline">$1</a>',
            $html,
        );
    }

    /**
     * @param  Collection<int, string>  $names
     */
    private static function highlightMentions(string $html, Collection $names): string
    {
        if ($names->isEmpty()) {
            return $html;
        }

        $pattern = '/(?<![\w@])@('.$names->implode('|').')(?![\w])/i';

        return (string) preg_replace_callback($pattern, fn (array $m) => '<span class="rounded bg-brand-500/10 px-1 font-medium text-brand-600 dark:text-brand-400">@'.$m[1].'</span>', $html);
    }

    /**
     * Extract the users mentioned with @-syntax in the given text, matched
     * against the supplied candidates by full name or — when unambiguous —
     * first name. Longest names match first so "@Sanne de Vries" beats "@Sanne",
     * and unknown names are ignored. Returns each matched user once.
     *
     * @param  Collection<int, User>  $candidates
     * @return Collection<int, User>
     */
    public static function extractUsers(string $text, Collection $candidates): Collection
    {
        if (trim($text) === '' || $candidates->isEmpty()) {
            return collect();
        }

        // Only treat a first name as a mention target when exactly one candidate
        // carries it, so an ambiguous "@Sanne" never pings the wrong person.
        $firstNameCounts = $candidates
            ->map(fn (User $user) => mb_strtolower(trim(Str::before($user->name, ' '))))
            ->countBy()
            ->all();

        /** @var array<string, User> $byName */
        $byName = [];

        foreach ($candidates as $user) {
            $full = mb_strtolower(trim($user->name));

            if ($full !== '') {
                $byName[$full] = $user;
            }

            $first = mb_strtolower(trim(Str::before($user->name, ' ')));

            if ($first !== '' && ($firstNameCounts[$first] ?? 0) === 1) {
                $byName[$first] ??= $user;
            }
        }

        $names = collect(array_keys($byName))
            ->sortByDesc(fn (string $name) => strlen($name))
            ->map(fn (string $name) => preg_quote($name, '/'))
            ->values();

        $pattern = '/(?<![\w@])@('.$names->implode('|').')(?![\w])/i';

        preg_match_all($pattern, $text, $matches);

        return collect($matches[1] ?? [])
            ->map(fn (string $name) => $byName[mb_strtolower($name)] ?? null)
            ->filter()
            ->unique(fn (User $user) => $user->id)
            ->values();
    }

    /**
     * Replace #123 references with a chip linking to that project's task. The
     * lookbehind keeps us out of URL fragments and HTML entities; unknown
     * numbers are left as plain text.
     */
    private static function renderTicketReferences(string $safe, Project $project): string
    {
        return preg_replace_callback('/(?<![\w\/#&])#(\d+)\b/', function (array $m) use ($project) {
            $number = (int) $m[1];

            $task = Task::query()
                ->where('project_id', $project->id)
                ->where('number', $number)
                ->first(['id', 'number']);

            if ($task === null) {
                return $m[0];
            }

            $url = route('projects.board', ['project' => $project->id, 'openTask' => $task->id]);
            $label = ($project->key ? $project->key.'-' : '#').$number;

            return '<a href="'.$url.'" wire:navigate class="rounded bg-brand-500/10 px-1 font-medium text-brand-600 dark:text-brand-400">'.e($label).'</a>';
        }, $safe);
    }

    /**
     * @return Collection<int, User>
     */
    private static function users(): Collection
    {
        return self::$cachedUsers ??= User::query()->get(['id', 'name']);
    }
}

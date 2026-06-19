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
     * Render message/comment text as safe HTML: escape, linkify URLs, highlight
     * user mentions and — within a project context — turn #123 ticket references
     * into chips that deep-link to the task.
     *
     * @param  Collection<int, User>|null  $users
     */
    public static function render(string $text, ?Collection $users = null, ?Project $project = null): string
    {
        $users ??= self::users();

        $safe = e($text);

        $safe = preg_replace(
            '~(https?://[^\s<]+)~',
            '<a href="$1" target="_blank" rel="noopener" class="text-brand-500 underline">$1</a>',
            $safe,
        );

        $names = $users
            ->flatMap(fn (User $user) => [$user->name, Str::before($user->name, ' ')])
            ->filter()
            ->unique()
            ->sortByDesc(fn (string $name) => strlen($name))
            ->map(fn (string $name) => preg_quote(e($name), '/'))
            ->values();

        if ($names->isNotEmpty()) {
            $pattern = '/(?<![\w@])@('.$names->implode('|').')(?![\w])/i';

            $safe = preg_replace_callback($pattern, fn (array $m) => '<span class="rounded bg-brand-500/10 px-1 font-medium text-brand-600 dark:text-brand-400">@'.$m[1].'</span>', $safe);
        }

        if ($project !== null) {
            $safe = self::renderTicketReferences($safe, $project);
        }

        return nl2br($safe);
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

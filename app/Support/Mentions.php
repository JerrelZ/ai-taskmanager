<?php

namespace App\Support;

use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

class Mentions
{
    /** @var Collection<int, User>|null */
    private static ?Collection $cachedUsers = null;

    /**
     * Render message/comment text as safe HTML: escape, linkify URLs and
     * highlight @mentions of known users.
     *
     * @param  Collection<int, User>|null  $users
     */
    public static function render(string $text, ?Collection $users = null): string
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

        return nl2br($safe);
    }

    /**
     * @return Collection<int, User>
     */
    private static function users(): Collection
    {
        return self::$cachedUsers ??= User::query()->get(['id', 'name']);
    }
}

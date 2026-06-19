<?php

namespace App\Support;

use App\Enums\TaskStatus;
use App\Models\Activity;
use App\Models\Message;
use App\Models\Task;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Builds the per-user daily recap payload: open assigned tasks, looming
 * deadlines, what others changed on those tasks in the last day, and the
 * number of unread chat messages.
 */
class DailyRecap
{
    /**
     * Tasks whose due date falls within this many days count as "upcoming".
     */
    private const DEADLINE_WINDOW_DAYS = 3;

    /**
     * How many unread chat messages to actually preview in the e-mail.
     */
    private const MESSAGE_PREVIEW_LIMIT = 5;

    /**
     * Assemble the recap for the given user.
     *
     * @return array{
     *     assignedTasks: Collection<int, Task>,
     *     deadlines: Collection<int, Task>,
     *     recentActivity: Collection<int, Activity>,
     *     unreadMessages: int,
     *     unreadMessagePreviews: Collection<int, Message>,
     *     hasActivity: bool,
     * }
     */
    public static function for(User $user): array
    {
        $openStatuses = array_map(
            fn (TaskStatus $status): string => $status->value,
            array_filter(TaskStatus::cases(), fn (TaskStatus $status): bool => ! $status->isComplete()),
        );

        $assignedTasks = Task::query()
            ->where('assignee_id', $user->id)
            ->whereIn('status', $openStatuses)
            ->with('project')
            ->orderByRaw('due_date is null, due_date asc')
            ->get();

        $deadlineCutoff = Carbon::today()->addDays(self::DEADLINE_WINDOW_DAYS)->endOfDay();

        $deadlines = $assignedTasks
            ->filter(fn (Task $task): bool => $task->due_date !== null && $task->due_date->lte($deadlineCutoff))
            ->values();

        $recentActivity = Activity::query()
            ->whereHas('task', fn ($query) => $query->where('assignee_id', $user->id))
            ->where('created_at', '>=', now()->subDay())
            ->where(fn ($query) => $query->whereNull('user_id')->orWhere('user_id', '!=', $user->id))
            ->with(['task', 'user'])
            ->latest()
            ->limit(25)
            ->get();

        $unreadMessages = $user->unreadMessagesCount();
        $unreadMessagePreviews = self::unreadMessagePreviews($user);

        $hasActivity = $deadlines->isNotEmpty()
            || $recentActivity->isNotEmpty()
            || $unreadMessages > 0;

        return [
            'assignedTasks' => $assignedTasks,
            'deadlines' => $deadlines,
            'recentActivity' => $recentActivity,
            'unreadMessages' => $unreadMessages,
            'unreadMessagePreviews' => $unreadMessagePreviews,
            'hasActivity' => $hasActivity,
        ];
    }

    /**
     * The most recent unread chat messages addressed to the user, with their
     * sender and conversation eager-loaded for rendering a preview.
     *
     * @return Collection<int, Message>
     */
    private static function unreadMessagePreviews(User $user): Collection
    {
        return Message::query()
            ->select('messages.*')
            ->join('conversation_user as cu', function ($join) use ($user) {
                $join->on('cu.conversation_id', '=', 'messages.conversation_id')
                    ->where('cu.user_id', '=', $user->id)
                    ->where('cu.muted', '=', false);
            })
            ->where('messages.user_id', '!=', $user->id)
            ->where(fn ($query) => $query
                ->whereNull('cu.last_read_at')
                ->orWhereColumn('messages.created_at', '>', 'cu.last_read_at'))
            ->with(['user', 'conversation.project', 'conversation.users'])
            ->latest('messages.created_at')
            ->limit(self::MESSAGE_PREVIEW_LIMIT)
            ->get();
    }
}

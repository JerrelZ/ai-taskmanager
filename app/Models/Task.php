<?php

namespace App\Models;

use App\Enums\TaskPriority;
use App\Enums\TaskReadiness;
use App\Enums\TaskStatus;
use App\Events\TaskBoardUpdated;
use App\Jobs\AssessTaskPromptReadiness;
use App\Services\TaskPromptBuilder;
use Carbon\CarbonInterface;
use Database\Factories\TaskFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * @property int $id
 * @property int $project_id
 * @property int|null $number
 * @property string|null $linear_id
 * @property int|null $parent_id
 * @property string $title
 * @property string|null $description
 * @property TaskStatus $status
 * @property TaskPriority $priority
 * @property int|null $assignee_id
 * @property Carbon|null $due_date
 * @property int $position
 * @property Carbon|null $reviewed_at
 * @property TaskReadiness|null $ai_readiness
 * @property list<string>|null $ai_missing
 * @property string|null $ai_prompt
 * @property Carbon|null $ai_assessed_at
 * @property int|null $created_by
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class Task extends Model
{
    /** @use HasFactory<TaskFactory> */
    use HasFactory;

    protected $fillable = [
        'project_id',
        'email_thread_id',
        'number',
        'previous_identifiers',
        'linear_id',
        'parent_id',
        'title',
        'description',
        'status',
        'priority',
        'assignee_id',
        'due_date',
        'position',
        'reviewed_at',
        'ai_readiness',
        'ai_missing',
        'ai_prompt',
        'ai_assessed_at',
        'created_by',
    ];

    /**
     * Number of days without an update before an open task is considered stale.
     */
    public const STALE_AFTER_DAYS = 14;

    protected static function booted(): void
    {
        static::creating(function (Task $task): void {
            if ($task->number === null && $task->project_id !== null) {
                $task->number = (int) static::where('project_id', $task->project_id)->max('number') + 1;
            }
        });

        // Re-assess prompt-readiness whenever the context that feeds the prompt
        // changes. The assessment job only writes ai_* columns, so the `updated`
        // guard below stays false for its own write-back (no recursion).
        static::created(function (Task $task): void {
            AssessTaskPromptReadiness::dispatch($task->id);
        });

        // Moving a ticket to another project changes its identifier (key + a
        // fresh per-project number). Remember the old identifier so links to it
        // keep resolving via a redirect.
        static::updating(function (Task $task): void {
            if (! $task->isDirty('project_id')) {
                return;
            }

            $oldKey = Project::whereKey($task->getOriginal('project_id'))->value('key');
            $oldNumber = $task->getOriginal('number') ?? $task->getOriginal('id');
            $oldIdentifier = ($oldKey ? $oldKey.'-' : '#').$oldNumber;

            $history = $task->previous_identifiers ?? [];

            if (! in_array($oldIdentifier, $history, true)) {
                $history[] = $oldIdentifier;
                $task->previous_identifiers = $history;
            }
        });

        static::updated(function (Task $task): void {
            if ($task->wasChanged(['title', 'description', 'project_id'])) {
                AssessTaskPromptReadiness::dispatch($task->id);
            }
        });
    }

    /**
     * Human reference like "WEB-12" (falls back to "#12" without a project key).
     */
    public function identifier(): string
    {
        $key = $this->relationLoaded('project') ? $this->project?->key : $this->project()->value('key');

        return ($key ? $key.'-' : '#').($this->number ?? $this->id);
    }

    /**
     * Decorative, human-readable slug for the ticket URL (the identifier does
     * the actual lookup, so this can safely drift when the title changes).
     */
    public function ticketSlug(): string
    {
        return Str::slug($this->title) ?: 'ticket';
    }

    /**
     * Canonical, shareable URL for this ticket. Falls back to the project board
     * deep link for tickets in a project without a key (identifier like "#12").
     */
    public function ticketUrl(): string
    {
        $key = $this->relationLoaded('project') ? $this->project?->key : $this->project()->value('key');

        if (! $key) {
            return route('projects.board', ['project' => $this->project_id, 'openTask' => $this->id]);
        }

        return route('tickets.show', ['identifier' => $this->identifier(), 'slug' => $this->ticketSlug()]);
    }

    /**
     * Deep link back to the original Linear issue this ticket was imported from,
     * or null when it has no Linear origin. The slug is omitted because Linear
     * redirects the bare identifier to the canonical URL.
     */
    public function linearUrl(): ?string
    {
        if (! $this->linear_id) {
            return null;
        }

        return 'https://linear.app/'.config('services.linear.workspace').'/issue/'.$this->linear_id;
    }

    /**
     * The canonical, deterministic Claude Code prompt for this ticket. Single
     * source of truth (also used by headless runs), so what you copy matches
     * what a run executes.
     */
    public function claudeCodePrompt(): string
    {
        return app(TaskPromptBuilder::class)->build($this);
    }

    /**
     * The best prompt to hand a developer: the AI-sharpened version when it has
     * been assessed, otherwise the freshly built one.
     */
    public function resolvedPrompt(): string
    {
        return filled($this->ai_prompt) ? $this->ai_prompt : $this->claudeCodePrompt();
    }

    /**
     * Scope to tickets the assessor judged fully paste-ready.
     *
     * @param  Builder<Task>  $query
     */
    public function scopePromptReady(Builder $query): void
    {
        $query->where('ai_readiness', TaskReadiness::Ready->value);
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => TaskStatus::class,
            'priority' => TaskPriority::class,
            'due_date' => 'date',
            'reviewed_at' => 'datetime',
            'ai_readiness' => TaskReadiness::class,
            'ai_missing' => 'array',
            'ai_assessed_at' => 'datetime',
            'previous_identifiers' => 'array',
        ];
    }

    /**
     * Scope to actionable (open) tasks: not done and not canceled.
     *
     * @param  Builder<Task>  $query
     */
    public function scopeActionable(Builder $query): void
    {
        $query->whereNotIn('status', [TaskStatus::Done->value, TaskStatus::Canceled->value]);
    }

    /**
     * Scope to root tasks (not subtasks).
     *
     * @param  Builder<Task>  $query
     */
    public function scopeRoots(Builder $query): void
    {
        $query->whereNull('parent_id');
    }

    /**
     * The next free position at the end of a status column, shared across every
     * project in the workspace. Root tasks are ordered globally per status so
     * the project board and the all-tickets board stay in sync.
     */
    public static function nextRootPosition(int $workspaceId, string $status): int
    {
        return (int) static::query()
            ->roots()
            ->where('status', $status)
            ->whereHas('project', fn (Builder $q) => $q->where('workspace_id', $workspaceId))
            ->max('position') + 1;
    }

    /**
     * Re-rank a root task inside its workspace-global status column using
     * neighbour anchoring, so the order stays consistent even when the visible
     * list is filtered or only shows a single project.
     *
     * Pass the ids displayed directly above and below the drop slot; the task is
     * spliced between them in the global sequence and the column is renumbered.
     */
    public static function reorderInStatus(int $workspaceId, string $status, int $taskId, ?int $anchorAboveId, ?int $anchorBelowId = null): void
    {
        $ids = static::query()
            ->roots()
            ->where('status', $status)
            ->whereHas('project', fn (Builder $q) => $q->where('workspace_id', $workspaceId))
            ->orderBy('position')
            ->orderBy('id')
            ->pluck('id')
            ->reject(fn ($id) => $id === $taskId)
            ->values()
            ->all();

        $insertAt = 0;

        if ($anchorAboveId !== null && ($above = array_search($anchorAboveId, $ids, true)) !== false) {
            $insertAt = $above + 1;
        } elseif ($anchorBelowId !== null && ($below = array_search($anchorBelowId, $ids, true)) !== false) {
            $insertAt = $below;
        }

        array_splice($ids, $insertAt, 0, [$taskId]);

        foreach ($ids as $position => $id) {
            static::whereKey($id)->update(['position' => $position]);
        }

        TaskBoardUpdated::dispatchQuietly($workspaceId);
    }

    /**
     * @return BelongsTo<Project, $this>
     */
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    /**
     * @return BelongsTo<EmailThread, $this>
     */
    public function emailThread(): BelongsTo
    {
        return $this->belongsTo(EmailThread::class);
    }

    /**
     * @return BelongsTo<Task, $this>
     */
    public function parent(): BelongsTo
    {
        return $this->belongsTo(Task::class, 'parent_id');
    }

    /**
     * @return HasMany<Task, $this>
     */
    public function subtasks(): HasMany
    {
        return $this->hasMany(Task::class, 'parent_id')->orderBy('position');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function assignee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assignee_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * @return BelongsToMany<Label, $this>
     */
    public function labels(): BelongsToMany
    {
        return $this->belongsToMany(Label::class);
    }

    /**
     * @return HasMany<Comment, $this>
     */
    public function comments(): HasMany
    {
        return $this->hasMany(Comment::class)->oldest();
    }

    /**
     * @return HasMany<Activity, $this>
     */
    public function activities(): HasMany
    {
        return $this->hasMany(Activity::class)->latest();
    }

    /**
     * @return MorphMany<Attachment, $this>
     */
    public function attachments(): MorphMany
    {
        return $this->morphMany(Attachment::class, 'attachable')->latest();
    }

    public function isSubtask(): bool
    {
        return $this->parent_id !== null;
    }

    public function isComplete(): bool
    {
        return $this->status->isComplete();
    }

    /**
     * The most recent moment the task was touched (reviewed or updated).
     */
    public function lastTouchedAt(): ?CarbonInterface
    {
        return $this->reviewed_at ?? $this->updated_at;
    }

    /**
     * An open task is stale when it hasn't been touched in a while.
     */
    public function isStale(): bool
    {
        if ($this->isComplete()) {
            return false;
        }

        $touched = $this->lastTouchedAt();

        return $touched !== null && $touched->lt(now()->subDays(self::STALE_AFTER_DAYS));
    }

    /**
     * Completed vs total subtask counts.
     *
     * @return array{done: int, total: int}
     */
    public function subtaskProgress(): array
    {
        $subtasks = $this->relationLoaded('subtasks') ? $this->subtasks : $this->subtasks()->get();

        return [
            'done' => $subtasks->filter->isComplete()->count(),
            'total' => $subtasks->count(),
        ];
    }
}

<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $task_id
 * @property int|null $user_id
 * @property string $type
 * @property array<string, mixed>|null $properties
 * @property Carbon|null $created_at
 */
class Activity extends Model
{
    protected $fillable = [
        'task_id',
        'user_id',
        'type',
        'properties',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'properties' => 'array',
        ];
    }

    /**
     * @return BelongsTo<Task, $this>
     */
    public function task(): BelongsTo
    {
        return $this->belongsTo(Task::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Human-readable, Dutch description of this activity.
     */
    public function description(): string
    {
        $props = $this->properties ?? [];

        return match ($this->type) {
            'created' => 'maakte deze ticket aan',
            'status' => 'wijzigde status naar '.($props['to'] ?? '?'),
            'priority' => 'wijzigde prioriteit naar '.($props['to'] ?? '?'),
            'assignee' => isset($props['to']) && $props['to'] !== null
                ? 'wees toe aan '.$props['to']
                : 'haalde de toewijzing weg',
            'due' => isset($props['to']) && $props['to'] !== null
                ? 'zette de deadline op '.$props['to']
                : 'haalde de deadline weg',
            'project' => 'verplaatste naar '.($props['to'] ?? '?'),
            'reviewed' => 'markeerde als bijgewerkt',
            'comment' => 'plaatste een reactie',
            default => $this->type,
        };
    }
}

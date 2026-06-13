<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One headless Claude Code run against a project's repository for a ticket.
 *
 * @property int $id
 * @property int $task_id
 * @property int|null $requested_by
 * @property string $status
 * @property string $prompt
 * @property string|null $output
 * @property string|null $error
 * @property Carbon|null $finished_at
 */
class ClaudeCodeRun extends Model
{
    public const STATUS_PENDING = 'pending';

    public const STATUS_RUNNING = 'running';

    public const STATUS_COMPLETED = 'completed';

    public const STATUS_FAILED = 'failed';

    protected $fillable = [
        'task_id',
        'requested_by',
        'status',
        'prompt',
        'output',
        'error',
        'finished_at',
    ];

    protected function casts(): array
    {
        return [
            'finished_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Task, $this>
     */
    public function task(): BelongsTo
    {
        return $this->belongsTo(Task::class);
    }

    public function isRunning(): bool
    {
        return in_array($this->status, [self::STATUS_PENDING, self::STATUS_RUNNING], true);
    }
}

<?php

namespace App\Support;

use App\Models\Task;
use Illuminate\Support\Facades\Auth;

class TaskActivity
{
    /**
     * Record an activity entry for a task.
     *
     * @param  array<string, mixed>  $properties
     */
    public static function log(Task $task, string $type, array $properties = []): void
    {
        $task->activities()->create([
            'user_id' => Auth::id(),
            'type' => $type,
            'properties' => $properties !== [] ? $properties : null,
        ]);
    }
}

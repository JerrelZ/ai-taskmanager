<?php

namespace App\Events;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Broadcast when a workspace's ticket board changes (a card is reordered, moved
 * to another column, created or bulk-edited) so every open board updates live.
 *
 * The payload is intentionally empty — clients re-fetch their own scoped view so
 * all authorization and ordering stays server-side, exactly like the chat does.
 *
 * Broadcasts synchronously (ShouldBroadcastNow) so a running queue worker is not
 * required for the board to stay live — only the Reverb server needs to be up.
 */
class TaskBoardUpdated implements ShouldBroadcastNow
{
    use Dispatchable;
    use InteractsWithSockets;
    use SerializesModels;

    public function __construct(public int $workspaceId) {}

    /**
     * @return array<int, PrivateChannel>
     */
    public function broadcastOn(): array
    {
        return [new PrivateChannel('workspace.'.$this->workspaceId.'.board')];
    }

    public function broadcastAs(): string
    {
        return 'board.updated';
    }
}

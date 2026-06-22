<?php

use App\Models\Conversation;
use App\Models\User;
use Illuminate\Support\Facades\Broadcast;

Broadcast::channel('App.Models.User.{id}', function ($user, $id) {
    return (int) $user->id === (int) $id;
});

// Only participants (workspace-fenced via canAccess) may subscribe to a
// conversation's realtime stream.
Broadcast::channel('conversation.{conversation}', function (User $user, Conversation $conversation) {
    return $conversation->canAccess($user);
});

// Live ticket board: only members of the workspace may subscribe to its stream.
Broadcast::channel('workspace.{workspaceId}.board', function (User $user, int $workspaceId) {
    return (int) $user->workspace_id === (int) $workspaceId;
});

<?php

namespace App\Livewire\Concerns;

use App\Enums\TaskPriority;
use App\Enums\TaskStatus;
use App\Models\Conversation;
use App\Models\Message;
use App\Support\TaskActivity;
use Flux\Flux;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;

/**
 * Shared reply and reaction handling for chat composers. The host component is
 * responsible for actually posting the message (and passing `$replyingToId` to
 * `Conversation::postMessage`) and for resetting `replyingToId` afterwards.
 */
trait ManagesChatInteractions
{
    public ?int $replyingToId = null;

    /**
     * Begin replying to a message the current user can see.
     */
    public function startReply(int $messageId): void
    {
        $message = $this->accessibleMessage($messageId);

        if ($message === null) {
            return;
        }

        $this->replyingToId = $message->id;

        $this->dispatch('reply-started');
    }

    public function cancelReply(): void
    {
        $this->replyingToId = null;
    }

    /**
     * Handle a "/ticket …" or "/task …" slash command typed into the composer:
     * create a ticket in the conversation's project from the text after the
     * command. Returns true when the input was a command (so the host should
     * skip posting it as a normal message).
     */
    protected function handleSlashCommand(Conversation $conversation): bool
    {
        if (! preg_match('~^/(?:ticket|task)\s+(.+)~is', trim($this->body), $matches)) {
            return false;
        }

        $project = $conversation->project;

        if ($project === null) {
            Flux::toast(variant: 'warning', text: __('Slash-commando’s werken alleen in projectgesprekken.'));

            return true;
        }

        $title = Str::limit(trim($matches[1]), 120, '');

        $task = $project->tasks()->create([
            'title' => $title,
            'status' => TaskStatus::Backlog,
            'priority' => TaskPriority::None,
            'created_by' => Auth::id(),
            'position' => 0,
        ]);

        TaskActivity::log($task, 'created');

        $this->reset('body', 'newChatAttachments', 'replyingToId');

        Flux::toast(variant: 'success', text: __('Ticket :id aangemaakt.', ['id' => $task->identifier()]));

        return true;
    }

    /**
     * The message currently being replied to, for the composer preview.
     */
    public function replyingToMessage(): ?Message
    {
        if ($this->replyingToId === null) {
            return null;
        }

        return Message::with('user')->find($this->replyingToId);
    }

    /**
     * Toggle the current user's emoji reaction on a message: add it if missing,
     * remove it if already present.
     */
    public function toggleReaction(int $messageId, string $emoji): void
    {
        $message = $this->accessibleMessage($messageId);

        if ($message === null) {
            return;
        }

        $existing = $message->reactions()
            ->where('user_id', Auth::id())
            ->where('emoji', $emoji)
            ->first();

        if ($existing !== null) {
            $existing->delete();
        } else {
            $message->reactions()->create([
                'user_id' => Auth::id(),
                'emoji' => $emoji,
            ]);
        }

        $this->refreshThread();
    }

    /**
     * Find a message the current user is allowed to interact with, or null.
     */
    private function accessibleMessage(int $messageId): ?Message
    {
        $message = Message::with('conversation')->find($messageId);

        if ($message === null || ! $message->conversation->canAccess(Auth::user())) {
            return null;
        }

        return $message;
    }

    /**
     * Drop the cached thread so reactions re-render. Host components expose the
     * thread as a computed property under different names.
     */
    private function refreshThread(): void
    {
        unset($this->thread);
    }
}

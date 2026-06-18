<?php

namespace App\Livewire\Projects;

use App\Livewire\Concerns\ManagesChatAttachments;
use App\Livewire\Concerns\ManagesChatInteractions;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\Project;
use App\Models\User;
use App\Services\AttachmentService;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Computed;
use Livewire\Component;
use Livewire\WithFileUploads;

class Chat extends Component
{
    use ManagesChatAttachments;
    use ManagesChatInteractions;
    use WithFileUploads;

    public Project $project;

    public Conversation $conversation;

    public string $body = '';

    public function mount(Project $project): void
    {
        $user = Auth::user();
        abort_unless($project->isVisibleTo($user), 403);

        $this->project = $project;
        $this->conversation = $project->channel();
        $this->conversation->markReadFor($user);
    }

    /**
     * Subscribe to the channel's realtime stream for instant message delivery
     * (the 3s poll remains a fallback).
     *
     * @return array<string, string>
     */
    public function getListeners(): array
    {
        return ["echo-private:conversation.{$this->conversation->id},.message.sent" => 'pollChat'];
    }

    /**
     * Called by the poll loop: pull in new messages and keep the channel marked
     * as read while the user has it open.
     */
    public function pollChat(): void
    {
        unset($this->thread);

        $this->conversation->markReadFor(Auth::user());
    }

    /**
     * @return Collection<int, Message>
     */
    #[Computed]
    public function thread(): Collection
    {
        // Most recent page only, newest-first then flipped to oldest-first.
        return $this->conversation->messages()
            ->with(['user', 'attachments', 'reactions', 'replyTo.user'])
            ->reorder()
            ->latest('id')
            ->limit($this->messageLimit)
            ->get()
            ->sortBy('id')
            ->values();
    }

    /**
     * Whether the channel has older messages beyond the loaded page.
     */
    public function hasMoreMessages(): bool
    {
        return $this->conversation->messages()->count() > $this->messageLimit;
    }

    /**
     * Team members available to @mention in the project chat.
     *
     * @return Collection<int, User>
     */
    #[Computed]
    public function people(): Collection
    {
        return User::query()
            ->inWorkspace($this->project->workspace_id)
            ->whereKeyNot(Auth::id())
            ->orderBy('name')
            ->get();
    }

    public function send(AttachmentService $attachments): void
    {
        $body = trim($this->body);

        if ($body === '' && $this->newChatAttachments === []) {
            return;
        }

        if ($this->handleSlashCommand($this->conversation)) {
            unset($this->thread);
            $this->dispatch('message-sent');

            return;
        }

        $this->validate($this->chatAttachmentRules());

        $message = $this->conversation->postMessage(Auth::user(), $body, $this->replyingToId);

        $this->storeChatAttachments($attachments, $message);

        $this->reset('body', 'newChatAttachments', 'replyingToId');

        unset($this->thread);

        $this->dispatch('message-sent');
    }
}

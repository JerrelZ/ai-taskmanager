<?php

namespace App\Livewire\Projects;

use App\Livewire\Concerns\ManagesChatAttachments;
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
        return $this->conversation->messages()->with(['user', 'attachments'])->get();
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

        $this->validate($this->chatAttachmentRules());

        $message = $this->conversation->postMessage(Auth::user(), $body);

        $this->storeChatAttachments($attachments, $message);

        $this->reset('body', 'newChatAttachments');

        unset($this->thread);

        $this->dispatch('message-sent');
    }
}

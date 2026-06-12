<?php

namespace App\Livewire\Messages;

use App\Enums\ConversationType;
use App\Enums\TaskPriority;
use App\Enums\TaskStatus;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\Project;
use App\Models\User;
use App\Services\MessageToTaskDrafter;
use App\Support\TaskActivity;
use Flux\Flux;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

#[Title('Berichten')]
class Index extends Component
{
    #[Url]
    public ?int $conversationId = null;

    public string $body = '';

    public ?int $newDmUserId = null;

    public string $newGroupName = '';

    /** @var array<int, int> */
    public array $newGroupMembers = [];

    public ?int $ticketMessageId = null;

    public ?int $ticketProjectId = null;

    public function mount(): void
    {
        if ($this->conversationId !== null) {
            $this->openConversation($this->conversationId);
        }
    }

    /**
     * Conversations the current user can see, most recent first.
     *
     * @return Collection<int, Conversation>
     */
    #[Computed]
    public function conversations(): Collection
    {
        return Conversation::query()
            ->visibleTo(Auth::user())
            ->with(['users', 'project', 'latestMessage.user'])
            ->orderByDesc('last_message_at')
            ->orderByDesc('id')
            ->get();
    }

    #[Computed]
    public function activeConversation(): ?Conversation
    {
        if ($this->conversationId === null) {
            return null;
        }

        $conversation = Conversation::with(['users', 'project'])->find($this->conversationId);

        if ($conversation === null || ! $conversation->canAccess(Auth::user())) {
            return null;
        }

        return $conversation;
    }

    /**
     * @return Collection<int, Message>
     */
    #[Computed]
    public function thread(): Collection
    {
        return $this->activeConversation?->messages()->with('user')->get() ?? collect();
    }

    /**
     * Team members available to start a DM or group with.
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

    public function openConversation(int $id): void
    {
        $conversation = Conversation::find($id);

        if ($conversation === null || ! $conversation->canAccess(Auth::user())) {
            return;
        }

        $this->conversationId = $conversation->id;
        $conversation->markReadFor(Auth::user());

        unset($this->conversations, $this->activeConversation, $this->messages);
    }

    public function send(): void
    {
        $conversation = $this->activeConversation;
        $body = trim($this->body);

        if ($conversation === null || $body === '') {
            return;
        }

        $conversation->postMessage(Auth::user(), $body);
        $conversation->markReadFor(Auth::user());

        $this->body = '';

        unset($this->conversations, $this->messages);
    }

    public function startDm(): void
    {
        abort_unless(Auth::user()->isTeam(), 403);

        $otherId = $this->newDmUserId;

        if ($otherId === null) {
            return;
        }

        $me = Auth::id();

        $conversation = Conversation::query()
            ->where('type', ConversationType::Dm->value)
            ->whereHas('users', fn ($q) => $q->whereKey($me))
            ->whereHas('users', fn ($q) => $q->whereKey($otherId))
            ->first();

        if ($conversation === null) {
            $conversation = Conversation::create([
                'type' => ConversationType::Dm,
                'created_by' => $me,
            ]);
            $conversation->users()->sync([$me, $otherId]);
        }

        $this->reset('newDmUserId');
        Flux::modal('new-dm')->close();

        $this->openConversation($conversation->id);
    }

    public function createGroup(): void
    {
        abort_unless(Auth::user()->isTeam(), 403);

        $validated = $this->validate([
            'newGroupName' => 'required|string|max:255',
        ]);

        $conversation = Conversation::create([
            'type' => ConversationType::Group,
            'name' => $validated['newGroupName'],
            'created_by' => Auth::id(),
            'last_message_at' => now(),
        ]);

        $members = User::query()
            ->whereIn('id', $this->newGroupMembers)
            ->pluck('id')
            ->push(Auth::id())
            ->unique()
            ->all();
        $conversation->users()->sync($members);

        $this->reset('newGroupName', 'newGroupMembers');
        Flux::modal('new-group')->close();

        $this->openConversation($conversation->id);
    }

    /**
     * Projects the user can create a ticket in.
     *
     * @return Collection<int, Project>
     */
    #[Computed]
    public function ticketProjects(): Collection
    {
        return Project::query()->visibleTo(Auth::user())->active()->orderBy('name')->get();
    }

    public function openTicketDraft(int $messageId): void
    {
        $message = $this->activeConversation?->messages()->find($messageId);

        if ($message === null) {
            return;
        }

        $this->ticketMessageId = $message->id;
        $this->ticketProjectId = $this->activeConversation?->project_id;

        Flux::modal('message-to-ticket')->show();
    }

    public function createTicketFromMessage(MessageToTaskDrafter $drafter): void
    {
        $message = Message::find($this->ticketMessageId);
        $project = Project::find($this->ticketProjectId);

        if ($message === null || $project === null) {
            return;
        }

        $user = Auth::user();
        abort_if(! $user->isTeam() && $project->client_id !== $user->client_id, 403);

        $draft = $drafter->draft($message->body, $project);

        $task = $project->tasks()->create([
            'title' => $draft['title'] !== '' ? $draft['title'] : 'Nieuwe ticket',
            'description' => $draft['description'],
            'status' => TaskStatus::Backlog,
            'priority' => TaskPriority::None,
            'created_by' => Auth::id(),
            'position' => 0,
        ]);

        TaskActivity::log($task, 'created');

        $this->reset('ticketMessageId', 'ticketProjectId');
        Flux::modal('message-to-ticket')->close();

        Flux::toast(
            variant: 'success',
            text: $draft['ai']
                ? __('Ticket :id aangemaakt (AI).', ['id' => $task->identifier()])
                : __('Ticket :id aangemaakt.', ['id' => $task->identifier()]),
        );
    }

    public function render(): View
    {
        return view('livewire.messages.index');
    }
}

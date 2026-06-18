<?php

namespace App\Livewire\Messages;

use App\Enums\ConversationType;
use App\Enums\TaskPriority;
use App\Enums\TaskStatus;
use App\Livewire\Concerns\ManagesChatAttachments;
use App\Livewire\Concerns\ManagesChatInteractions;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\Project;
use App\Models\User;
use App\Services\AttachmentService;
use App\Services\MessageToTaskDrafter;
use App\Support\TaskActivity;
use Flux\Flux;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithFileUploads;

#[Title('Berichten')]
class Index extends Component
{
    use ManagesChatAttachments;
    use ManagesChatInteractions;
    use WithFileUploads;

    #[Url]
    public ?int $conversationId = null;

    public string $search = '';

    public string $body = '';

    public ?int $newDmUserId = null;

    public string $newGroupName = '';

    public ?int $newGroupProjectId = null;

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
     * Subscribe to the open conversation's realtime stream so a new message
     * shows instantly (the 3s poll stays as a fallback when Reverb is down).
     *
     * @return array<string, string>
     */
    public function getListeners(): array
    {
        if ($this->conversationId === null) {
            return [];
        }

        return ["echo-private:conversation.{$this->conversationId},.message.sent" => 'pollMessages'];
    }

    /**
     * Conversations the current user can see, most recent first.
     *
     * @return Collection<int, Conversation>
     */
    #[Computed]
    public function conversations(): Collection
    {
        $conversations = Conversation::query()
            ->visibleTo(Auth::user())
            ->with(['users', 'project', 'latestMessage.user'])
            ->orderByDesc('last_message_at')
            ->orderByDesc('id')
            ->get();

        $term = trim($this->search);

        if ($term === '') {
            return $conversations;
        }

        // Match on the conversation title (DM partner, group or project name) or
        // the latest message body; titles are derived in PHP so we filter here.
        $me = Auth::user();

        return $conversations->filter(fn (Conversation $conversation) => Str::contains(
            $conversation->titleFor($me).' '.($conversation->latestMessage?->body ?? ''),
            $term,
            ignoreCase: true,
        ))->values();
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
        $conversation = $this->activeConversation;

        if ($conversation === null) {
            return collect();
        }

        // Load only the most recent page, newest-first, then flip to oldest-first
        // for display so long histories don't load (or render) all at once.
        return $conversation->messages()
            ->with(['user', 'attachments', 'reactions', 'replyTo.user'])
            ->reorder()
            ->latest('id')
            ->limit($this->messageLimit)
            ->get()
            ->sortBy('id')
            ->values();
    }

    /**
     * Whether the open conversation has older messages beyond the loaded page.
     */
    public function hasMoreMessages(): bool
    {
        $conversation = $this->activeConversation;

        return $conversation !== null && $conversation->messages()->count() > $this->messageLimit;
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
            ->inWorkspace(Auth::user()->workspace_id)
            ->whereKeyNot(Auth::id())
            ->orderBy('name')
            ->get();
    }

    /**
     * Projects the user can start a group within.
     *
     * @return Collection<int, Project>
     */
    #[Computed]
    public function groupProjects(): Collection
    {
        return Project::query()->visibleTo(Auth::user())->active()->orderBy('name')->get();
    }

    /**
     * Members selectable for a new group: only people with access to the chosen
     * project, so a group can never mix people from different projects.
     *
     * @return Collection<int, User>
     */
    #[Computed]
    public function groupMembers(): Collection
    {
        $project = $this->visibleProject($this->newGroupProjectId);

        if ($project === null) {
            return collect();
        }

        return $project->accessibleUsers()->whereKeyNot(Auth::id())->orderBy('name')->get();
    }

    /**
     * Resolve a project the current user is allowed to start a group in.
     */
    private function visibleProject(?int $id): ?Project
    {
        if ($id === null) {
            return null;
        }

        return Project::query()->visibleTo(Auth::user())->active()->whereKey($id)->first();
    }

    public function updatedNewGroupProjectId(): void
    {
        $this->newGroupMembers = [];

        unset($this->groupMembers);
    }

    public function openConversation(int $id): void
    {
        $conversation = Conversation::find($id);

        if ($conversation === null || ! $conversation->canAccess(Auth::user())) {
            return;
        }

        $this->conversationId = $conversation->id;
        $this->messageLimit = 30;
        $conversation->markReadFor(Auth::user());

        unset($this->conversations, $this->activeConversation, $this->messages, $this->activeMuted);

        $this->dispatchUnreadCount();
    }

    /**
     * Push the fresh unread total to the browser so the document-title badge
     * (e.g. "(3) Berichten") stays current without a full page reload.
     */
    private function dispatchUnreadCount(): void
    {
        $this->dispatch('unread-messages-changed', count: Auth::user()->unreadMessagesCount());
    }

    /**
     * Whether the current user has muted the open conversation.
     */
    #[Computed]
    public function activeMuted(): bool
    {
        if ($this->conversationId === null) {
            return false;
        }

        $conversation = Conversation::find($this->conversationId);

        return $conversation !== null && $conversation->isMutedFor(Auth::user());
    }

    public function toggleMute(): void
    {
        if ($this->conversationId === null) {
            return;
        }

        $conversation = Conversation::find($this->conversationId);

        if ($conversation === null || ! $conversation->canAccess(Auth::user())) {
            return;
        }

        $user = Auth::user();
        $conversation->setMutedFor($user, ! $conversation->isMutedFor($user));

        unset($this->activeMuted);
    }

    /**
     * Called by the poll loop: pull in new messages and keep the open
     * conversation marked as read so its unread badge does not climb while the
     * user is looking at it.
     */
    public function pollMessages(): void
    {
        unset($this->conversations, $this->activeConversation, $this->thread, $this->messages);

        if ($this->conversationId === null) {
            return;
        }

        $conversation = Conversation::with('users')->find($this->conversationId);

        if ($conversation === null || ! $conversation->canAccess(Auth::user())) {
            return;
        }

        if ($conversation->unreadCountFor(Auth::user()) > 0) {
            $conversation->markReadFor(Auth::user());

            unset($this->conversations);
        }

        $this->dispatchUnreadCount();
    }

    public function send(AttachmentService $attachments): void
    {
        $conversation = $this->activeConversation;
        $body = trim($this->body);

        if ($conversation === null || ($body === '' && $this->newChatAttachments === [])) {
            return;
        }

        if ($this->handleSlashCommand($conversation)) {
            unset($this->conversations, $this->messages);
            $this->dispatch('message-sent');

            return;
        }

        $this->validate($this->chatAttachmentRules());

        $message = $conversation->postMessage(Auth::user(), $body, $this->replyingToId);

        $this->storeChatAttachments($attachments, $message);

        $conversation->markReadFor(Auth::user());

        $this->reset('body', 'newChatAttachments', 'replyingToId');

        unset($this->conversations, $this->messages);

        $this->dispatch('message-sent');
    }

    /**
     * Open the "new conversation" modal, pre-selecting the first contact so a
     * chat can be started in one tap; the user can still pick someone else.
     */
    public function openNewDm(): void
    {
        abort_unless(Auth::user()->isTeam(), 403);

        $this->newDmUserId = $this->people->first()?->id;

        Flux::modal('new-dm')->show();
    }

    public function startDm(): void
    {
        abort_unless(Auth::user()->isTeam(), 403);

        $this->validate(
            ['newDmUserId' => 'required|integer|exists:users,id'],
            ['newDmUserId.required' => __('Kies een persoon om mee te chatten.')],
        );

        $otherId = $this->newDmUserId;
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
            'newGroupProjectId' => 'required|integer',
            'newGroupMembers' => 'array',
        ]);

        $project = $this->visibleProject($validated['newGroupProjectId']);

        if ($project === null) {
            $this->addError('newGroupProjectId', __('Kies een geldig project.'));

            return;
        }

        // Members must all have access to the chosen project, so a group can
        // never span people from different projects.
        $members = $project->accessibleUsers()
            ->whereIn('id', $this->newGroupMembers)
            ->pluck('id')
            ->push(Auth::id())
            ->unique()
            ->all();

        $conversation = Conversation::create([
            'type' => ConversationType::Group,
            'name' => $validated['newGroupName'],
            'project_id' => $project->id,
            'created_by' => Auth::id(),
            'last_message_at' => now(),
        ]);

        $conversation->users()->sync($members);

        $this->reset('newGroupName', 'newGroupProjectId', 'newGroupMembers');
        unset($this->groupMembers);
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

        abort_unless($project->isVisibleTo(Auth::user()), 403);

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

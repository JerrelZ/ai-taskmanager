<?php

namespace App\Livewire\Messages;

use App\Enums\ConversationType;
use App\Enums\TaskPriority;
use App\Enums\TaskStatus;
use App\Events\TaskBoardUpdated;
use App\Livewire\Concerns\ManagesChatAttachments;
use App\Livewire\Concerns\ManagesChatInteractions;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\Project;
use App\Models\Task;
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

    public string $ticketTitle = '';

    public string $ticketDescription = '';

    public string $ticketPriority = 'none';

    /** True while the AI draft is being generated, so the modal can show a skeleton. */
    public bool $ticketDrafting = false;

    /** Whether the current draft text came from the AI (vs. the heuristic fallback). */
    public bool $ticketAiDrafted = false;

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
        $listeners = ['generate-ticket-draft' => 'generateTicketDraft'];

        if ($this->conversationId !== null) {
            $listeners["echo-private:conversation.{$this->conversationId},.message.sent"] = 'pollMessages';
        }

        return $listeners;
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

    /**
     * Open the draft modal at once with a skeleton, then let the AI fill the
     * fields in a follow-up request (dispatched event) so the UI stays snappy.
     */
    public function openTicketDraft(int $messageId): void
    {
        $message = $this->activeConversation?->messages()->find($messageId);

        if ($message === null) {
            return;
        }

        $this->ticketMessageId = $message->id;
        // Default to the conversation's project, otherwise the first visible one
        // so a single-project workspace needs no manual selection.
        $this->ticketProjectId = $this->activeConversation?->project_id ?? $this->ticketProjects->first()?->id;
        $this->reset('ticketTitle', 'ticketDescription', 'ticketAiDrafted');
        $this->ticketPriority = 'none';
        $this->ticketDrafting = true;

        Flux::modal('message-to-ticket')->show();
        $this->dispatch('generate-ticket-draft');
    }

    /**
     * Ask the AI for a title + description, using the surrounding conversation
     * as context. Also bound to the "regenerate" button in the modal.
     */
    public function generateTicketDraft(MessageToTaskDrafter $drafter): void
    {
        $message = $this->activeConversation?->messages()->find($this->ticketMessageId);

        if ($message === null) {
            $this->ticketDrafting = false;

            return;
        }

        $draft = $drafter->draft(
            (string) $message->body,
            Project::find($this->ticketProjectId),
            $this->conversationContext($message),
        );

        $this->ticketTitle = $draft['title'] !== '' ? $draft['title'] : Str::limit(trim((string) $message->body), 80, '');
        $this->ticketDescription = $draft['description'];
        $this->ticketAiDrafted = $draft['ai'];
        $this->ticketDrafting = false;
    }

    /**
     * The most recent conversation lines up to the focal message, oldest first,
     * as "Naam: tekst" so the AI can ground the ticket in the discussion.
     *
     * @return array<int, string>
     */
    private function conversationContext(Message $focal): array
    {
        $conversation = $this->activeConversation;

        if ($conversation === null) {
            return [];
        }

        return $conversation->messages()
            ->with('user')
            ->where('id', '<=', $focal->id)
            ->reorder()
            ->latest('id')
            ->limit(15)
            ->get()
            ->sortBy('id')
            ->map(fn (Message $message) => ($message->user?->name ?? 'Onbekend').': '.Str::limit(trim((string) $message->body), 400))
            ->values()
            ->all();
    }

    public function createTicketFromMessage(): void
    {
        $validated = $this->validate([
            'ticketProjectId' => ['required', 'integer', 'exists:projects,id'],
            'ticketTitle' => ['required', 'string', 'max:250'],
            'ticketDescription' => ['nullable', 'string', 'max:5000'],
            'ticketPriority' => ['required', 'string', 'in:none,urgent,high,medium,low'],
        ], [
            'ticketProjectId.required' => __('Kies een project.'),
            'ticketTitle.required' => __('Geef de ticket een titel.'),
        ]);

        $project = Project::findOrFail($validated['ticketProjectId']);

        abort_unless($project->isVisibleTo(Auth::user()), 403);

        $task = $project->tasks()->create([
            'title' => $validated['ticketTitle'],
            'description' => $validated['ticketDescription'] ?: null,
            'status' => TaskStatus::Backlog,
            'priority' => TaskPriority::from($validated['ticketPriority']),
            'created_by' => Auth::id(),
            'position' => Task::nextRootPosition($project->workspace_id, TaskStatus::Backlog->value),
        ]);

        TaskActivity::log($task, 'created');
        TaskBoardUpdated::dispatch($project->workspace_id);

        $aiDrafted = $this->ticketAiDrafted;
        $this->reset('ticketMessageId', 'ticketProjectId', 'ticketTitle', 'ticketDescription', 'ticketAiDrafted');
        $this->ticketPriority = 'none';
        Flux::modal('message-to-ticket')->close();

        Flux::toast(
            variant: 'success',
            text: $aiDrafted
                ? __('Ticket :id aangemaakt (AI).', ['id' => $task->identifier()])
                : __('Ticket :id aangemaakt.', ['id' => $task->identifier()]),
        );
    }

    public function render(): View
    {
        return view('livewire.messages.index');
    }
}

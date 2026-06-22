<?php

namespace App\Livewire\Tasks;

use App\Enums\TaskPriority;
use App\Enums\TaskStatus;
use App\Livewire\Concerns\CopiesTaskPrompt;
use App\Models\Conversation;
use App\Models\Label;
use App\Models\Task;
use App\Models\User;
use App\Notifications\MentionNotification;
use App\Services\AttachmentService;
use App\Support\Mentions;
use App\Support\TaskActivity;
use Flux\Flux;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use Livewire\Attributes\Computed;
use Livewire\Attributes\On;
use Livewire\Component;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use Livewire\WithFileUploads;

class TaskDetail extends Component
{
    use CopiesTaskPrompt;
    use WithFileUploads;

    public ?int $taskId = null;

    /** @var array<int, TemporaryUploadedFile> */
    public array $newAttachments = [];

    /** @var array<int, TemporaryUploadedFile> */
    public array $newCommentAttachments = [];

    // Editable fields
    public string $title = '';

    public ?string $description = null;

    public string $status = 'backlog';

    public string $priority = 'none';

    public ?int $assigneeId = null;

    public ?string $dueDate = null;

    /** @var array<int, int> */
    public array $selectedLabels = [];

    public string $newSubtaskTitle = '';

    public string $newComment = '';

    public string $newLabelName = '';

    /**
     * Load a task into the panel and open it.
     */
    #[On('open-task')]
    public function open(int $taskId): void
    {
        $task = Task::with('project')->findOrFail($taskId);

        abort_unless($task->project->isVisibleTo(Auth::user()), 403);

        $this->taskId = $task->id;
        $this->title = $task->title;
        $this->description = $task->description;
        $this->status = $task->status->value;
        $this->priority = $task->priority->value;
        $this->assigneeId = $task->assignee_id;
        $this->dueDate = $task->due_date?->format('Y-m-d');
        $this->selectedLabels = $task->labels()->pluck('labels.id')->all();
        $this->reset('newSubtaskTitle', 'newComment', 'newCommentAttachments', 'newLabelName');
        $this->resetValidation();

        Flux::modal('task-detail')->show();
    }

    /**
     * The currently open task with its relations. Re-checks visibility on every
     * access so a tampered `taskId` (a public, client-settable property) can
     * never read or mutate a task outside the user's workspace/client — every
     * mutation flows through here.
     */
    #[Computed]
    public function task(): ?Task
    {
        if ($this->taskId === null) {
            return null;
        }

        $task = Task::query()
            ->with(['project', 'subtasks.assignee', 'comments.user', 'comments.attachments', 'activities.user', 'parent', 'attachments.uploader'])
            ->find($this->taskId);

        if ($task === null || ! $task->project?->isVisibleTo(Auth::user())) {
            return null;
        }

        return $task;
    }

    /**
     * Copy the ticket's shareable URL to the clipboard so it can be sent to a
     * colleague. Reuses the same browser clipboard listener as the prompt copy.
     */
    public function copyLink(): void
    {
        $task = $this->task();

        if ($task === null) {
            return;
        }

        $this->dispatch('copy-to-clipboard', text: $task->ticketUrl());

        Flux::toast(text: __('Link gekopieerd.'), variant: 'success');
    }

    /**
     * Conversations the current user can post into, for the "share to chat"
     * picker in the header.
     *
     * @return Collection<int, Conversation>
     */
    #[Computed]
    public function shareConversations(): Collection
    {
        return Conversation::query()
            ->visibleTo(Auth::user())
            ->with(['users', 'project'])
            ->orderByDesc('last_message_at')
            ->orderByDesc('id')
            ->get();
    }

    /**
     * Post the ticket's identifier, title and shareable link into a chosen
     * conversation the user has access to.
     */
    public function sendToChat(int $conversationId): void
    {
        $task = $this->task();

        if ($task === null) {
            return;
        }

        $conversation = Conversation::query()
            ->visibleTo(Auth::user())
            ->find($conversationId);

        if ($conversation === null) {
            return;
        }

        $conversation->postMessage(
            Auth::user(),
            $task->identifier().' — '.$task->title."\n".$task->ticketUrl(),
        );

        Flux::toast(text: __('Gedeeld in :chat', ['chat' => $conversation->titleFor(Auth::user())]), variant: 'success');
    }

    public function uploadAttachments(AttachmentService $attachments): void
    {
        $task = $this->task();

        if ($task === null) {
            return;
        }

        $this->validate([
            'newAttachments' => ['required', 'array', 'max:10'],
            'newAttachments.*' => ['file', 'max:25600'], // 25 MB each
        ]);

        foreach ($this->newAttachments as $file) {
            $attachments->storeUpload($file, $task, Auth::user());
        }

        $this->reset('newAttachments');
        unset($this->task);
        Flux::toast(variant: 'success', text: __('Bijlage(n) toegevoegd.'));
    }

    public function removeNewAttachment(int $index): void
    {
        unset($this->newAttachments[$index]);
        $this->newAttachments = array_values($this->newAttachments);
    }

    public function deleteAttachment(int $attachmentId): void
    {
        $task = $this->task();

        if ($task === null) {
            return;
        }

        $task->attachments()->whereKey($attachmentId)->first()?->delete();

        unset($this->task);
    }

    /**
     * @return Collection<int, User>
     */
    #[Computed]
    public function users(): Collection
    {
        return User::query()->inWorkspace(Auth::user()->workspace_id)->orderBy('name')->get();
    }

    /**
     * @return Collection<int, Label>
     */
    #[Computed]
    public function labels(): Collection
    {
        return Label::query()->orderBy('name')->get();
    }

    /**
     * @return array<int, TaskStatus>
     */
    public function statuses(): array
    {
        return TaskStatus::cases();
    }

    /**
     * @return array<int, TaskPriority>
     */
    public function priorities(): array
    {
        return TaskPriority::cases();
    }

    /**
     * Persist core fields whenever one of them changes.
     */
    public function updated(string $property): void
    {
        if ($this->taskId === null) {
            return;
        }

        if (in_array($property, ['title', 'status', 'priority', 'assigneeId', 'dueDate'], true)) {
            $this->saveTask();
        }
    }

    /**
     * Persist the (rich-text) description on demand from the editor.
     */
    public function saveDescription(): void
    {
        $this->saveTask();
    }

    /**
     * Strip dangerous markup from editor HTML before persisting.
     */
    private function sanitizeHtml(?string $html): ?string
    {
        if ($html === null || trim(strip_tags($html)) === '') {
            return null;
        }

        $html = preg_replace('#<\s*(script|style|iframe|object|embed)[^>]*>.*?<\s*/\s*\1\s*>#is', '', $html);
        $html = preg_replace('#\son\w+\s*=\s*("[^"]*"|\'[^\']*\'|[^\s>]+)#i', '', $html);
        $html = preg_replace('#(href|src)\s*=\s*("|\')?\s*javascript:[^"\'>]*("|\')?#i', '', $html);

        return $html;
    }

    public function saveTask(): void
    {
        $task = $this->task;

        if ($task === null) {
            return;
        }

        $this->validate([
            'title' => 'required|string|max:255',
            'description' => 'nullable|string',
            'status' => 'required|in:'.implode(',', array_column(TaskStatus::cases(), 'value')),
            'priority' => 'required|in:'.implode(',', array_column(TaskPriority::cases(), 'value')),
            'assigneeId' => 'nullable|exists:users,id',
            'dueDate' => 'nullable|date',
        ]);

        $newStatus = TaskStatus::from($this->status);
        $newPriority = TaskPriority::from($this->priority);
        $newAssigneeId = $this->assigneeId ?: null;
        $newDue = $this->dueDate ?: null;

        // Capture changes for the activity log before persisting.
        if ($task->status !== $newStatus) {
            TaskActivity::log($task, 'status', ['from' => $task->status->label(), 'to' => $newStatus->label()]);
        }
        if ($task->priority !== $newPriority) {
            TaskActivity::log($task, 'priority', ['from' => $task->priority->label(), 'to' => $newPriority->label()]);
        }
        if ($task->assignee_id !== $newAssigneeId) {
            TaskActivity::log($task, 'assignee', ['to' => $newAssigneeId ? User::find($newAssigneeId)?->name : null]);
        }
        if ($task->due_date?->format('Y-m-d') !== $newDue) {
            TaskActivity::log($task, 'due', ['to' => $newDue]);
        }

        $task->update([
            'title' => $this->title,
            'description' => $this->sanitizeHtml($this->description),
            'status' => $newStatus,
            'priority' => $newPriority,
            'assignee_id' => $newAssigneeId,
            'due_date' => $newDue,
        ]);

        unset($this->task);
        $this->dispatch('task-saved');
    }

    public function toggleLabel(int $labelId): void
    {
        $task = $this->task;

        if ($task === null) {
            return;
        }

        if (in_array($labelId, $this->selectedLabels, true)) {
            $task->labels()->detach($labelId);
            $this->selectedLabels = array_values(array_diff($this->selectedLabels, [$labelId]));
        } else {
            $task->labels()->attach($labelId);
            $this->selectedLabels[] = $labelId;
        }

        unset($this->task);
        $this->dispatch('task-saved');
    }

    public function createLabel(): void
    {
        $name = trim($this->newLabelName);

        if ($name === '') {
            return;
        }

        $label = Label::create([
            'name' => $name,
            'color' => fake()->randomElement(['blue', 'green', 'amber', 'red', 'purple', 'pink', 'indigo', 'orange']),
        ]);

        $this->newLabelName = '';
        unset($this->labels);

        $this->toggleLabel($label->id);
    }

    public function addSubtask(): void
    {
        $task = $this->task;
        $title = trim($this->newSubtaskTitle);

        if ($task === null || $title === '') {
            return;
        }

        $maxPosition = (int) $task->subtasks()->max('position');

        $subtask = $task->subtasks()->create([
            'project_id' => $task->project_id,
            'title' => $title,
            'status' => TaskStatus::Todo,
            'priority' => TaskPriority::None,
            'position' => $maxPosition + 1,
            'created_by' => Auth::id(),
        ]);

        TaskActivity::log($subtask, 'created');

        $this->newSubtaskTitle = '';
        unset($this->task);
        $this->dispatch('task-saved');
    }

    public function toggleSubtask(int $subtaskId): void
    {
        $subtask = $this->task?->subtasks->firstWhere('id', $subtaskId);

        if ($subtask === null) {
            return;
        }

        $subtask->update([
            'status' => $subtask->isComplete() ? TaskStatus::Todo : TaskStatus::Done,
        ]);

        unset($this->task);
        $this->dispatch('task-saved');
    }

    public function addComment(AttachmentService $attachments): void
    {
        $task = $this->task;
        $body = trim($this->newComment);

        // A reply needs either text or at least one file to be meaningful.
        if ($task === null || ($body === '' && count($this->newCommentAttachments) === 0)) {
            return;
        }

        $this->validate([
            'newCommentAttachments' => ['array', 'max:10'],
            'newCommentAttachments.*' => ['file', 'max:25600'], // 25 MB each
        ]);

        $comment = $task->comments()->create([
            'user_id' => Auth::id(),
            'body' => $body,
        ]);

        // Files posted in the reply stay attached to the task (so they appear
        // among all of its attachments) while also pointing at this comment.
        foreach ($this->newCommentAttachments as $file) {
            $attachment = $attachments->storeUpload($file, $task, Auth::user());
            $attachment->update(['comment_id' => $comment->id]);
        }

        TaskActivity::log($task, 'comment', ['comment_id' => $comment->id]);

        $this->notifyMentionedUsers($task, $body);

        $this->reset('newComment', 'newCommentAttachments');
        unset($this->task);
        $this->dispatch('task-saved');
    }

    public function removeNewCommentAttachment(int $index): void
    {
        unset($this->newCommentAttachments[$index]);
        $this->newCommentAttachments = array_values($this->newCommentAttachments);
    }

    /**
     * Notify users @mentioned in a comment. Only people who can see the project
     * and who opted into message notifications are pinged, so a mention follows
     * the same preferences as a chat message.
     */
    private function notifyMentionedUsers(Task $task, string $body): void
    {
        $candidates = $this->users->reject(fn (User $user) => $user->id === Auth::id());

        $mentioned = Mentions::extractUsers($body, $candidates)
            ->filter(fn (User $user) => $user->wantsRealtimeMessageNotifications()
                && $task->project->isVisibleTo($user));

        if ($mentioned->isEmpty()) {
            return;
        }

        $title = __(':sender noemde je in :task', [
            'sender' => Auth::user()->name,
            'task' => $task->identifier(),
        ]);
        $url = $task->ticketUrl();
        $preview = Str::limit($body, 120);

        foreach ($mentioned as $user) {
            $user->notify(new MentionNotification($title, $preview, $url, 'task-'.$task->id));
        }
    }

    public function markReviewed(): void
    {
        $task = $this->task;

        if ($task === null) {
            return;
        }

        $task->update(['reviewed_at' => now()]);

        TaskActivity::log($task, 'reviewed');

        unset($this->task);
        $this->dispatch('task-saved');
    }

    public function deleteTask(): void
    {
        $task = $this->task;

        if ($task === null) {
            return;
        }

        $task->delete();

        $this->taskId = null;
        Flux::modal('task-detail')->close();
        $this->dispatch('task-saved');
    }
}

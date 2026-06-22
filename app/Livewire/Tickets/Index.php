<?php

namespace App\Livewire\Tickets;

use App\Enums\TaskPriority;
use App\Enums\TaskStatus;
use App\Events\TaskBoardUpdated;
use App\Livewire\Concerns\CopiesTaskPrompt;
use App\Livewire\Concerns\LimitsBoardColumns;
use App\Livewire\Concerns\PollsLiveBoard;
use App\Models\Label;
use App\Models\Project;
use App\Models\Task;
use App\Models\User;
use App\Support\TaskActivity;
use Flux\Flux;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

#[Title('Alle tickets')]
class Index extends Component
{
    use CopiesTaskPrompt;
    use LimitsBoardColumns;
    use PollsLiveBoard;

    #[Url]
    public string $search = '';

    #[Url]
    public ?int $projectFilter = null;

    #[Url]
    public ?int $assigneeFilter = null;

    #[Url]
    public ?int $labelFilter = null;

    #[Url]
    public ?string $priorityFilter = null;

    #[Url]
    public bool $onlyStale = false;

    #[Url]
    public bool $showCompleted = false;

    /** @var array<int, int> Ticket ids currently selected for a bulk action. */
    public array $selectedTickets = [];

    /** Status column the "new ticket" modal will create into. */
    public ?string $newTicketStatus = null;

    public ?int $newTicketProjectId = null;

    public string $newTicketTitle = '';

    public function mount(): void
    {
        $this->rememberBoardSignature();
    }

    /**
     * Filtered root tickets across all visible projects, ordered by their
     * workspace-global position within each status column.
     *
     * @return Collection<int, Task>
     */
    #[Computed]
    public function tickets(): Collection
    {
        $user = Auth::user();

        $tickets = Task::query()
            ->roots()
            ->whereHas('project', fn ($q) => $q->visibleTo($user)->active())
            ->with(['project', 'assignee', 'labels', 'subtasks'])
            ->withCount('comments')
            ->when(! $this->showCompleted, fn ($q) => $q->actionable())
            ->when($this->projectFilter, fn ($q) => $q->where('project_id', $this->projectFilter))
            ->when($this->assigneeFilter, fn ($q) => $q->where('assignee_id', $this->assigneeFilter))
            ->when($this->priorityFilter, fn ($q) => $q->where('priority', $this->priorityFilter))
            ->when($this->labelFilter, fn ($q) => $q->whereHas('labels', fn ($l) => $l->whereKey($this->labelFilter)))
            ->when($this->search !== '', fn ($q) => $q->where('title', 'like', '%'.$this->search.'%'))
            ->orderBy('position')
            ->orderBy('id')
            ->get();

        if ($this->onlyStale) {
            $tickets = $tickets->filter->isStale()->values();
        }

        return $tickets;
    }

    /**
     * Visible tickets grouped per status column, mixing every project.
     *
     * @return array<string, Collection<int, Task>>
     */
    #[Computed]
    public function columns(): array
    {
        $grouped = [];

        foreach ($this->statuses() as $status) {
            $grouped[$status->value] = $this->tickets->where('status', $status)->values();
        }

        return $grouped;
    }

    /**
     * Status columns to render. Completed columns only show when toggled on.
     *
     * @return array<int, TaskStatus>
     */
    public function statuses(): array
    {
        if ($this->showCompleted) {
            return TaskStatus::cases();
        }

        return [TaskStatus::Backlog, TaskStatus::Todo, TaskStatus::InProgress];
    }

    /**
     * The single ticket to work on now: the top of the most active actionable
     * column (In Progress, then Todo, then Backlog).
     */
    #[Computed]
    public function nowTask(): ?Task
    {
        foreach ([TaskStatus::InProgress, TaskStatus::Todo, TaskStatus::Backlog] as $status) {
            $task = Task::query()
                ->roots()
                ->where('status', $status->value)
                ->whereHas('project', fn ($q) => $q->visibleTo(Auth::user())->active())
                ->with('project')
                ->orderBy('position')
                ->orderBy('id')
                ->first();

            if ($task !== null) {
                return $task;
            }
        }

        return null;
    }

    /**
     * @return Collection<int, Project>
     */
    #[Computed]
    public function projects(): Collection
    {
        return Project::query()->visibleTo(Auth::user())->active()->orderBy('name')->get();
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
     * @return array<int, TaskPriority>
     */
    public function priorities(): array
    {
        return TaskPriority::cases();
    }

    public function hasActiveFilters(): bool
    {
        return $this->projectFilter !== null
            || $this->assigneeFilter !== null
            || $this->labelFilter !== null
            || $this->priorityFilter !== null
            || $this->onlyStale
            || $this->search !== '';
    }

    public function clearFilters(): void
    {
        $this->reset('projectFilter', 'assigneeFilter', 'labelFilter', 'priorityFilter', 'onlyStale', 'search');
    }

    /**
     * Drag-and-drop across the all-tickets board: move a ticket to a status
     * column and set its workspace-global priority within that column.
     *
     * Neighbour-anchored so the order stays consistent under active filters,
     * and shared with each project board (same `position` field).
     */
    public function moveTask(int $id, int $position, string $status): void
    {
        // Cross-project priority ordering is a team concept.
        if (! Auth::user()->isTeam()) {
            return;
        }

        $task = Task::query()
            ->roots()
            ->whereHas('project', fn ($q) => $q->visibleTo(Auth::user()))
            ->find($id);

        if ($task === null) {
            return;
        }

        $newStatus = TaskStatus::from($status);

        $displayed = $this->columns[$newStatus->value]
            ->pluck('id')
            ->reject(fn ($x) => $x === $task->id)
            ->values()
            ->all();

        $position = max(0, min($position, count($displayed)));
        $anchorAbove = $position > 0 ? $displayed[$position - 1] : null;
        $anchorBelow = $position < count($displayed) ? $displayed[$position] : null;

        if ($task->status !== $newStatus) {
            TaskActivity::log($task, 'status', ['from' => $task->status->label(), 'to' => $newStatus->label()]);
            $task->update(['status' => $newStatus]);
        }

        Task::reorderInStatus($task->project->workspace_id, $newStatus->value, $task->id, $anchorAbove, $anchorBelow);

        unset($this->tickets, $this->columns, $this->nowTask);
    }

    public function markReviewed(int $id): void
    {
        // Scope by visible project so a forged id can't touch another tenant.
        $task = Task::query()
            ->whereHas('project', fn ($q) => $q->visibleTo(Auth::user()))
            ->findOrFail($id);
        $task->update(['reviewed_at' => now()]);

        TaskActivity::log($task, 'reviewed');

        unset($this->tickets, $this->columns);

        Flux::toast(text: __('Gemarkeerd als bijgewerkt.'));
    }

    /**
     * Change a ticket's priority from the inline picker, scoped to the user's
     * workspace so a forged id can't touch another tenant.
     */
    public function setPriority(int $id, string $priority): void
    {
        $task = Task::query()
            ->whereHas('project', fn ($q) => $q->visibleTo(Auth::user()))
            ->findOrFail($id);
        $newPriority = TaskPriority::from($priority);

        if ($task->priority === $newPriority) {
            return;
        }

        TaskActivity::log($task, 'priority', ['from' => $task->priority->label(), 'to' => $newPriority->label()]);
        $task->update(['priority' => $newPriority]);

        unset($this->tickets, $this->columns, $this->nowTask);
    }

    /**
     * Change a ticket's status from the inline picker.
     */
    public function setStatus(int $id, string $status): void
    {
        $task = $this->authorizedTask($id);
        $newStatus = TaskStatus::from($status);

        if ($task->status === $newStatus) {
            return;
        }

        $position = Task::nextRootPosition($task->project->workspace_id, $newStatus->value);

        TaskActivity::log($task, 'status', ['from' => $task->status->label(), 'to' => $newStatus->label()]);
        $task->update(['status' => $newStatus, 'position' => $position]);

        TaskBoardUpdated::dispatchQuietly($task->project->workspace_id);

        unset($this->tickets, $this->columns, $this->nowTask);
    }

    /**
     * Assign a ticket from the inline picker. The assignee must be a member of
     * the current workspace, so a forged id can never reach across tenants.
     */
    public function setAssignee(int $id, ?int $userId): void
    {
        $task = $this->authorizedTask($id);
        $userId = $userId ?: null;

        if ($userId !== null && ! $this->users->contains('id', $userId)) {
            return;
        }

        if ($task->assignee_id === $userId) {
            return;
        }

        TaskActivity::log($task, 'assignee', ['to' => $userId ? User::find($userId)?->name : null]);
        $task->update(['assignee_id' => $userId]);

        unset($this->tickets, $this->columns, $this->nowTask);
    }

    /**
     * Set or clear a ticket's deadline from the inline picker.
     */
    public function setDue(int $id, ?string $due): void
    {
        $task = $this->authorizedTask($id);
        $due = $due ?: null;

        if ($due !== null && strtotime($due) === false) {
            return;
        }

        if ($task->due_date?->format('Y-m-d') === $due) {
            return;
        }

        TaskActivity::log($task, 'due', ['to' => $due]);
        $task->update(['due_date' => $due]);

        unset($this->tickets, $this->columns, $this->nowTask);
    }

    /**
     * Toggle a label on a ticket from the inline picker.
     */
    public function toggleLabel(int $id, int $labelId): void
    {
        $task = $this->authorizedTask($id);

        if (! $this->labels->contains('id', $labelId)) {
            return;
        }

        $task->labels()->toggle($labelId);

        unset($this->tickets, $this->columns);
    }

    /**
     * Resolve a ticket the current user is allowed to touch. Scoping by visible
     * project keeps a forged id from reaching another tenant's task.
     */
    private function authorizedTask(int $id): Task
    {
        return Task::query()
            ->whereHas('project', fn ($q) => $q->visibleTo(Auth::user()))
            ->findOrFail($id);
    }

    /**
     * Toggle every visible ticket in or out of the bulk selection.
     */
    public function toggleSelectAll(): void
    {
        $visible = $this->tickets->pluck('id')->all();

        $this->selectedTickets = count($this->selectedTickets) === count($visible) && $visible !== []
            ? []
            : $visible;
    }

    public function clearSelection(): void
    {
        $this->selectedTickets = [];
    }

    /**
     * The selected tickets the current user is allowed to act on.
     *
     * @return Collection<int, Task>
     */
    private function selectedTasks(): Collection
    {
        if ($this->selectedTickets === []) {
            return collect();
        }

        return Task::query()
            ->whereHas('project', fn ($q) => $q->visibleTo(Auth::user()))
            ->whereIn('id', $this->selectedTickets)
            ->get();
    }

    public function bulkSetStatus(string $status): void
    {
        if (! Auth::user()->isTeam()) {
            return;
        }

        $newStatus = TaskStatus::from($status);

        foreach ($this->selectedTasks() as $task) {
            if ($task->status === $newStatus) {
                continue;
            }

            TaskActivity::log($task, 'status', ['from' => $task->status->label(), 'to' => $newStatus->label()]);
            $task->update(['status' => $newStatus]);
        }

        $this->afterBulk(__(':count tickets bijgewerkt.', ['count' => count($this->selectedTickets)]));
    }

    public function bulkSetPriority(string $priority): void
    {
        if (! Auth::user()->isTeam()) {
            return;
        }

        $newPriority = TaskPriority::from($priority);

        foreach ($this->selectedTasks() as $task) {
            if ($task->priority === $newPriority) {
                continue;
            }

            TaskActivity::log($task, 'priority', ['from' => $task->priority->label(), 'to' => $newPriority->label()]);
            $task->update(['priority' => $newPriority]);
        }

        $this->afterBulk(__(':count tickets bijgewerkt.', ['count' => count($this->selectedTickets)]));
    }

    public function bulkSetAssignee(?int $userId): void
    {
        if (! Auth::user()->isTeam()) {
            return;
        }

        $userId = $userId ?: null;

        if ($userId !== null && ! $this->users->contains('id', $userId)) {
            return;
        }

        $name = $userId ? User::find($userId)?->name : null;

        foreach ($this->selectedTasks() as $task) {
            if ($task->assignee_id === $userId) {
                continue;
            }

            TaskActivity::log($task, 'assignee', ['to' => $name]);
            $task->update(['assignee_id' => $userId]);
        }

        $this->afterBulk(__(':count tickets bijgewerkt.', ['count' => count($this->selectedTickets)]));
    }

    public function bulkAddLabel(int $labelId): void
    {
        if (! Auth::user()->isTeam() || ! $this->labels->contains('id', $labelId)) {
            return;
        }

        foreach ($this->selectedTasks() as $task) {
            $task->labels()->syncWithoutDetaching([$labelId]);
        }

        $this->afterBulk(__('Label toegevoegd aan :count tickets.', ['count' => count($this->selectedTickets)]));
    }

    public function bulkSetProject(int $projectId): void
    {
        if (! Auth::user()->isTeam()) {
            return;
        }

        $project = $this->projects()->firstWhere('id', $projectId);

        if ($project === null) {
            return;
        }

        foreach ($this->selectedTasks() as $task) {
            if ($task->project_id === $project->id) {
                continue;
            }

            $from = $task->project->name;
            $this->moveTaskToProject($task, $project);
            TaskActivity::log($task, 'project', ['from' => $from, 'to' => $project->name]);
        }

        $this->afterBulk(__(':count tickets verplaatst naar :project.', [
            'count' => count($this->selectedTickets),
            'project' => $project->name,
        ]));
    }

    /**
     * Move a task and its subtasks to another project, giving each a fresh
     * per-project number so identifiers stay unique in the target project.
     */
    private function moveTaskToProject(Task $task, Project $project): void
    {
        $task->update([
            'project_id' => $project->id,
            'number' => (int) Task::where('project_id', $project->id)->max('number') + 1,
        ]);

        foreach ($task->subtasks as $subtask) {
            $this->moveTaskToProject($subtask, $project);
        }
    }

    public function bulkMarkReviewed(): void
    {
        if (! Auth::user()->isTeam()) {
            return;
        }

        foreach ($this->selectedTasks() as $task) {
            $task->update(['reviewed_at' => now()]);
            TaskActivity::log($task, 'reviewed');
        }

        $this->afterBulk(__(':count tickets gemarkeerd als bijgewerkt.', ['count' => count($this->selectedTickets)]));
    }

    public function bulkDelete(): void
    {
        if (! Auth::user()->isTeam()) {
            return;
        }

        $count = $this->selectedTasks()->each(fn (Task $task) => $task->delete())->count();

        $this->afterBulk(__(':count tickets verwijderd.', ['count' => $count]));
    }

    /**
     * Clear the selection, drop cached lists and confirm the bulk action.
     */
    private function afterBulk(string $message): void
    {
        $this->selectedTickets = [];
        unset($this->tickets, $this->columns, $this->nowTask);
        $this->dispatch('task-saved');
        TaskBoardUpdated::dispatchQuietly(Auth::user()->workspace_id);

        Flux::toast(variant: 'success', text: $message);
    }

    public function openTask(int $taskId): void
    {
        $this->dispatch('open-task', taskId: $taskId);
    }

    /**
     * Open the "new ticket" modal for a status column. On this cross-project
     * board a ticket needs a project, so default to the active project filter
     * (or the first visible project) and let the user confirm.
     */
    public function openNewTicket(string $status): void
    {
        $this->newTicketStatus = $status;
        $this->newTicketProjectId = $this->projectFilter ?? $this->projects()->value('id');
        $this->newTicketTitle = '';
        $this->resetValidation();

        Flux::modal('new-ticket')->show();
    }

    public function createTicket(): void
    {
        $this->validate([
            'newTicketProjectId' => ['required', Rule::in($this->projects()->pluck('id')->all())],
            'newTicketTitle' => ['required', 'string', 'max:255'],
        ]);

        $project = $this->projects()->firstWhere('id', $this->newTicketProjectId);
        $status = TaskStatus::from($this->newTicketStatus ?? TaskStatus::Todo->value);

        $task = $project->tasks()->create([
            'title' => trim($this->newTicketTitle),
            'status' => $status,
            'priority' => TaskPriority::None,
            'position' => Task::nextRootPosition($project->workspace_id, $status->value),
            'created_by' => Auth::id(),
        ]);

        TaskActivity::log($task, 'created');
        TaskBoardUpdated::dispatchQuietly($project->workspace_id);

        Flux::modal('new-ticket')->close();
        $this->reset('newTicketTitle', 'newTicketStatus');
        $this->refreshList();
    }

    /**
     * Refresh on the local save event and on the workspace's live board stream,
     * so a reorder by a teammate (or this user in another tab) shows up at once.
     *
     * @return array<string, string>
     */
    public function getListeners(): array
    {
        $workspaceId = Auth::user()->workspace_id;

        return [
            'task-saved' => 'refreshList',
            "echo-private:workspace.{$workspaceId}.board,.board.updated" => 'refreshList',
        ];
    }

    public function refreshList(): void
    {
        $this->forgetBoardCache();
        $this->rememberBoardSignature();
    }

    /**
     * @return Builder<Task>
     */
    protected function boardSignatureScope(): Builder
    {
        return Task::query()
            ->roots()
            ->whereHas('project', fn ($q) => $q->visibleTo(Auth::user())->active());
    }

    protected function forgetBoardCache(): void
    {
        unset($this->tickets, $this->columns, $this->nowTask);
    }

    public function render(): View
    {
        return view('livewire.tickets.index');
    }
}

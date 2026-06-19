<?php

namespace App\Livewire\Tickets;

use App\Enums\TaskPriority;
use App\Enums\TaskStatus;
use App\Livewire\Concerns\CopiesTaskPrompt;
use App\Models\Label;
use App\Models\Project;
use App\Models\Task;
use App\Models\User;
use App\Support\TaskActivity;
use Flux\Flux;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Computed;
use Livewire\Attributes\On;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

#[Title('Alle tickets')]
class Index extends Component
{
    use CopiesTaskPrompt;

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

    /**
     * Filtered, globally ranked root tickets across all projects.
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
            ->orderBy('rank')
            ->orderBy('id')
            ->get();

        if ($this->onlyStale) {
            $tickets = $tickets->filter->isStale()->values();
        }

        return $tickets;
    }

    /**
     * The single highest-priority actionable ticket — "work on this now".
     */
    #[Computed]
    public function nowTask(): ?Task
    {
        return Task::query()
            ->roots()
            ->actionable()
            ->whereHas('project', fn ($q) => $q->visibleTo(Auth::user())->active())
            ->with('project')
            ->orderBy('rank')
            ->orderBy('id')
            ->first();
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
     * Drag-and-drop: set a ticket's absolute rank across all projects.
     *
     * Uses neighbour-anchoring so the new order is consistent even when
     * the visible list is filtered.
     */
    public function reorder(int $id, int $position): void
    {
        // Global priority ordering is a team concept.
        if (! Auth::user()->isTeam()) {
            return;
        }

        // Never rank a task outside the user's workspace, even with a forged id.
        $visibleInWorkspace = fn ($q) => $q->whereHas('project', fn ($p) => $p->visibleTo(Auth::user()));

        if (! Task::query()->tap($visibleInWorkspace)->whereKey($id)->exists()) {
            return;
        }

        $displayed = $this->tickets->pluck('id')->reject(fn ($x) => $x === $id)->values()->all();

        $position = max(0, min($position, count($displayed)));
        $anchorAboveId = $position > 0 ? $displayed[$position - 1] : null;

        $global = Task::query()
            ->tap($visibleInWorkspace)
            ->roots()
            ->actionable()
            ->orderBy('rank')
            ->orderBy('id')
            ->pluck('id')
            ->reject(fn ($x) => $x === $id)
            ->values()
            ->all();

        if ($anchorAboveId === null) {
            array_unshift($global, $id);
        } else {
            $index = array_search($anchorAboveId, $global, true);
            if ($index === false) {
                $global[] = $id;
            } else {
                array_splice($global, $index + 1, 0, [$id]);
            }
        }

        foreach ($global as $newRank => $taskId) {
            Task::whereKey($taskId)->update(['rank' => $newRank]);
        }

        unset($this->tickets, $this->nowTask);
    }

    public function markReviewed(int $id): void
    {
        // Scope by visible project so a forged id can't touch another tenant.
        $task = Task::query()
            ->whereHas('project', fn ($q) => $q->visibleTo(Auth::user()))
            ->findOrFail($id);
        $task->update(['reviewed_at' => now()]);

        TaskActivity::log($task, 'reviewed');

        unset($this->tickets);

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

        unset($this->tickets, $this->nowTask);
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

        TaskActivity::log($task, 'status', ['from' => $task->status->label(), 'to' => $newStatus->label()]);
        $task->update(['status' => $newStatus]);

        unset($this->tickets, $this->nowTask);
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

        unset($this->tickets, $this->nowTask);
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

        unset($this->tickets, $this->nowTask);
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

        unset($this->tickets);
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
        unset($this->tickets, $this->nowTask);
        $this->dispatch('task-saved');

        Flux::toast(variant: 'success', text: $message);
    }

    public function openTask(int $taskId): void
    {
        $this->dispatch('open-task', taskId: $taskId);
    }

    #[On('task-saved')]
    public function refreshList(): void
    {
        unset($this->tickets, $this->nowTask);
    }

    public function render(): View
    {
        return view('livewire.tickets.index');
    }
}

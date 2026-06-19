<?php

namespace App\Livewire\Tickets;

use App\Enums\TaskPriority;
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

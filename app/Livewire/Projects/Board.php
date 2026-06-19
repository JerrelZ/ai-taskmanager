<?php

namespace App\Livewire\Projects;

use App\Enums\ProjectStatus;
use App\Enums\TaskPriority;
use App\Enums\TaskStatus;
use App\Livewire\Concerns\CopiesTaskPrompt;
use App\Models\Client;
use App\Models\Label;
use App\Models\Project;
use App\Models\Task;
use App\Models\User;
use App\Support\TaskActivity;
use Flux\Flux;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use Livewire\Attributes\Computed;
use Livewire\Attributes\On;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

#[Title('Board')]
class Board extends Component
{
    use CopiesTaskPrompt;

    public Project $project;

    /** kanban | list */
    #[Url(as: 'view')]
    public string $boardView = 'kanban';

    #[Url]
    public ?int $assigneeFilter = null;

    #[Url]
    public ?int $labelFilter = null;

    #[Url]
    public ?string $priorityFilter = null;

    #[Url]
    public string $search = '';

    /** Deep-link target: a task to open on load (e.g. from a chat #ref chip). */
    #[Url]
    public ?int $openTask = null;

    /** @var array<string, string> Quick-create title per status column. */
    public array $newTaskTitle = [];

    // Project settings form
    public string $editName = '';

    public string $editColor = 'blue';

    public string $editDescription = '';

    public string $editRepoPath = '';

    public string $editStack = '';

    public string $editContext = '';

    public string $editKey = '';

    public string $editStatus = 'active';

    public ?int $editClientId = null;

    public function mount(Project $project): void
    {
        abort_unless($project->isVisibleTo(Auth::user()), 403);

        $this->project = $project;

        // Open a deep-linked task once the detail panel is listening.
        if ($this->openTask !== null && $project->tasks()->whereKey($this->openTask)->exists()) {
            $this->dispatch('open-task', taskId: $this->openTask);
        }
    }

    public function canManageProject(): bool
    {
        return Auth::user()->isTeam();
    }

    public function openProjectSettings(): void
    {
        abort_unless(Auth::user()->isTeam(), 403);

        $this->editName = $this->project->name;
        $this->editColor = $this->project->color;
        $this->editDescription = (string) $this->project->description;
        $this->editRepoPath = (string) $this->project->repo_path;
        $this->editStack = (string) $this->project->stack;
        $this->editContext = (string) $this->project->context;
        $this->editKey = (string) $this->project->key;
        $this->editStatus = $this->project->status->value;
        $this->editClientId = $this->project->client_id;

        Flux::modal('project-settings')->show();
    }

    /**
     * @return Collection<int, Client>
     */
    #[Computed]
    public function clients(): Collection
    {
        return Client::query()->orderBy('name')->get();
    }

    /**
     * @return array<int, ProjectStatus>
     */
    public function projectStatuses(): array
    {
        return ProjectStatus::cases();
    }

    public function saveProject(): void
    {
        abort_unless(Auth::user()->isTeam(), 403);

        $validated = $this->validate([
            'editName' => 'required|string|max:255',
            'editKey' => 'required|string|max:10|alpha_num|unique:projects,key,'.$this->project->id,
            'editColor' => 'required|string|max:30',
            'editClientId' => 'nullable|exists:clients,id',
            'editStatus' => 'required|in:'.implode(',', array_column(ProjectStatus::cases(), 'value')),
            'editDescription' => 'nullable|string',
            'editRepoPath' => 'nullable|string|max:255',
            'editStack' => 'nullable|string|max:255',
            'editContext' => 'nullable|string',
        ]);

        $this->project->update([
            'name' => $validated['editName'],
            'key' => Str::upper($validated['editKey']),
            'color' => $validated['editColor'],
            'client_id' => $validated['editClientId'] ?: null,
            'status' => ProjectStatus::from($validated['editStatus']),
            'description' => $validated['editDescription'] !== '' ? $validated['editDescription'] : null,
            'repo_path' => $validated['editRepoPath'] !== '' ? $validated['editRepoPath'] : null,
            'stack' => $validated['editStack'] !== '' ? $validated['editStack'] : null,
            'context' => $validated['editContext'] !== '' ? $validated['editContext'] : null,
        ]);

        Flux::modal('project-settings')->close();
        Flux::toast(variant: 'success', text: __('Project bijgewerkt.'));
    }

    /**
     * Filtered, ordered root tasks for this project.
     *
     * @return Collection<int, Task>
     */
    #[Computed]
    public function tasks(): Collection
    {
        return $this->project->rootTasks()
            ->with(['project', 'assignee', 'labels', 'subtasks'])
            ->withCount('comments')
            ->when($this->assigneeFilter, fn ($q) => $q->where('assignee_id', $this->assigneeFilter))
            ->when($this->priorityFilter, fn ($q) => $q->where('priority', $this->priorityFilter))
            ->when($this->labelFilter, fn ($q) => $q->whereHas('labels', fn ($l) => $l->whereKey($this->labelFilter)))
            ->when($this->search !== '', fn ($q) => $q->where('title', 'like', '%'.$this->search.'%'))
            ->orderBy('position')
            ->orderBy('id')
            ->get();
    }

    /**
     * Tasks grouped per status column.
     *
     * @return array<string, Collection<int, Task>>
     */
    #[Computed]
    public function columns(): array
    {
        $grouped = [];

        foreach (TaskStatus::cases() as $status) {
            $grouped[$status->value] = $this->tasks->where('status', $status)->values();
        }

        return $grouped;
    }

    /**
     * @return array<int, TaskStatus>
     */
    public function statuses(): array
    {
        return TaskStatus::cases();
    }

    /**
     * @return Collection<int, User>
     */
    #[Computed]
    public function users(): Collection
    {
        return User::query()->inWorkspace($this->project->workspace_id)->orderBy('name')->get();
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
        return $this->assigneeFilter !== null
            || $this->labelFilter !== null
            || $this->priorityFilter !== null
            || $this->search !== '';
    }

    public function clearFilters(): void
    {
        $this->reset('assigneeFilter', 'labelFilter', 'priorityFilter', 'search');
    }

    /**
     * Drag-and-drop handler: move a task to a column at a given position and reorder siblings.
     */
    public function moveTask(int $id, int $position, string $status): void
    {
        $task = $this->project->rootTasks()->findOrFail($id);
        $newStatus = TaskStatus::from($status);

        $siblings = $this->project->rootTasks()
            ->where('status', $newStatus->value)
            ->whereKeyNot($task->id)
            ->orderBy('position')
            ->orderBy('id')
            ->pluck('id')
            ->all();

        $position = max(0, min($position, count($siblings)));
        array_splice($siblings, $position, 0, [$task->id]);

        foreach ($siblings as $index => $siblingId) {
            Task::whereKey($siblingId)->update(['position' => $index]);
        }

        if ($task->status !== $newStatus) {
            TaskActivity::log($task, 'status', ['from' => $task->status->label(), 'to' => $newStatus->label()]);
            $task->update(['status' => $newStatus]);
        }

        unset($this->tasks, $this->columns);
    }

    /**
     * Move a task to another column from the card status picker (mobile-friendly alternative to dragging).
     */
    public function setStatus(int $id, string $status): void
    {
        $task = $this->project->rootTasks()->findOrFail($id);
        $newStatus = TaskStatus::from($status);

        if ($task->status === $newStatus) {
            return;
        }

        $maxPosition = (int) $this->project->rootTasks()->where('status', $newStatus->value)->max('position');

        TaskActivity::log($task, 'status', ['from' => $task->status->label(), 'to' => $newStatus->label()]);
        $task->update(['status' => $newStatus, 'position' => $maxPosition + 1]);

        unset($this->tasks, $this->columns);
    }

    /**
     * Change a task's priority from the inline picker on a card or row.
     */
    public function setPriority(int $id, string $priority): void
    {
        $task = $this->project->rootTasks()->findOrFail($id);
        $newPriority = TaskPriority::from($priority);

        if ($task->priority === $newPriority) {
            return;
        }

        TaskActivity::log($task, 'priority', ['from' => $task->priority->label(), 'to' => $newPriority->label()]);
        $task->update(['priority' => $newPriority]);

        unset($this->tasks, $this->columns);
    }

    /**
     * Assign a task from the inline picker on a card or row.
     */
    public function setAssignee(int $id, ?int $userId): void
    {
        $task = $this->project->rootTasks()->findOrFail($id);
        $userId = $userId ?: null;

        if ($userId !== null && ! $this->users->contains('id', $userId)) {
            return;
        }

        if ($task->assignee_id === $userId) {
            return;
        }

        TaskActivity::log($task, 'assignee', ['to' => $userId ? User::find($userId)?->name : null]);
        $task->update(['assignee_id' => $userId]);

        unset($this->tasks, $this->columns);
    }

    /**
     * Set or clear a task's deadline from the inline picker on a card or row.
     */
    public function setDue(int $id, ?string $due): void
    {
        $task = $this->project->rootTasks()->findOrFail($id);
        $due = $due ?: null;

        if ($due !== null && strtotime($due) === false) {
            return;
        }

        if ($task->due_date?->format('Y-m-d') === $due) {
            return;
        }

        TaskActivity::log($task, 'due', ['to' => $due]);
        $task->update(['due_date' => $due]);

        unset($this->tasks, $this->columns);
    }

    /**
     * Toggle a label on a task from the inline picker on a card or row.
     */
    public function toggleLabel(int $id, int $labelId): void
    {
        $task = $this->project->rootTasks()->findOrFail($id);

        if (! $this->labels->contains('id', $labelId)) {
            return;
        }

        $task->labels()->toggle($labelId);

        unset($this->tasks, $this->columns);
    }

    public function createTask(string $status): void
    {
        $title = trim($this->newTaskTitle[$status] ?? '');

        if ($title === '') {
            return;
        }

        $statusEnum = TaskStatus::from($status);
        $maxPosition = (int) $this->project->rootTasks()->where('status', $status)->max('position');

        $task = $this->project->tasks()->create([
            'title' => $title,
            'status' => $statusEnum,
            'priority' => TaskPriority::None,
            'position' => $maxPosition + 1,
            'created_by' => Auth::id(),
        ]);

        TaskActivity::log($task, 'created');

        $this->newTaskTitle[$status] = '';

        unset($this->tasks, $this->columns);
    }

    public function openTask(int $taskId): void
    {
        $this->dispatch('open-task', taskId: $taskId);
    }

    #[On('task-saved')]
    public function refreshBoard(): void
    {
        unset($this->tasks, $this->columns);
    }
}

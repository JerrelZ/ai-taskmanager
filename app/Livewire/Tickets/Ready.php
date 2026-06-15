<?php

namespace App\Livewire\Tickets;

use App\Enums\TaskReadiness;
use App\Jobs\AssessTaskPromptReadiness;
use App\Livewire\Concerns\CopiesTaskPrompt;
use App\Models\Project;
use App\Models\Task;
use Flux\Flux;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Computed;
use Livewire\Attributes\On;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * The paste-ready work queue: tickets the assessor judged ready to hand to
 * Claude Code, plus the "almost there" ones with what they still need.
 */
#[Title('Klaar voor Claude Code')]
class Ready extends Component
{
    use CopiesTaskPrompt;

    #[Url]
    public ?int $projectFilter = null;

    /**
     * Fully paste-ready tickets, highest priority first.
     *
     * @return Collection<int, Task>
     */
    #[Computed]
    public function readyTickets(): Collection
    {
        return $this->baseQuery()->promptReady()->get();
    }

    /**
     * Workable tickets that still miss some context.
     *
     * @return Collection<int, Task>
     */
    #[Computed]
    public function almostTickets(): Collection
    {
        return $this->baseQuery()->where('ai_readiness', TaskReadiness::Almost->value)->get();
    }

    /**
     * @return Builder<Task>
     */
    private function baseQuery()
    {
        return Task::query()
            ->roots()
            ->actionable()
            ->whereHas('project', fn ($q) => $q->visibleTo(Auth::user())->active())
            ->with(['project', 'assignee', 'labels', 'subtasks'])
            ->withCount('comments')
            ->when($this->projectFilter, fn ($q) => $q->where('project_id', $this->projectFilter))
            ->orderBy('rank')
            ->orderBy('id');
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
     * Re-run the readiness assessment for a single ticket on demand.
     */
    public function reassess(int $taskId): void
    {
        $task = Task::query()
            ->whereHas('project', fn ($q) => $q->visibleTo(Auth::user()))
            ->findOrFail($taskId);

        AssessTaskPromptReadiness::dispatch($task->id);

        Flux::toast(text: __('Opnieuw beoordelen gestart — ververs zo even.'));
    }

    public function openTask(int $taskId): void
    {
        $this->dispatch('open-task', taskId: $taskId);
    }

    #[On('task-saved')]
    public function refreshList(): void
    {
        unset($this->readyTickets, $this->almostTickets);
    }

    public function render(): View
    {
        return view('livewire.tickets.ready');
    }
}

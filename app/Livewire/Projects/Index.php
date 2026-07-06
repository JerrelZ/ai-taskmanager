<?php

namespace App\Livewire\Projects;

use App\Enums\UserRole;
use App\Models\Client;
use App\Models\Project;
use App\Models\User;
use Flux\Flux;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Attributes\Validate;
use Livewire\Component;

#[Title('Projecten')]
class Index extends Component
{
    #[Validate('required|string|max:255')]
    public string $name = '';

    #[Validate('required|string|max:30')]
    public string $color = 'blue';

    public string $description = '';

    public string $repoPath = '';

    public string $stack = '';

    public string $context = '';

    public ?int $clientId = null;

    /** @var array<int, int> Ids of the members granted access to the new project. */
    public array $memberIds = [];

    public function canManage(): bool
    {
        return Auth::user()->isTeam();
    }

    /**
     * Team members (excluding admins, who always have access) that can be
     * granted access to a project.
     *
     * @return Collection<int, User>
     */
    #[Computed]
    public function teamMembers(): Collection
    {
        return User::query()
            ->inWorkspace(Auth::user()->workspace_id)
            ->where('role', UserRole::Member->value)
            ->orderBy('name')
            ->get();
    }

    /**
     * @return Collection<int, Client>
     */
    #[Computed]
    public function clients(): Collection
    {
        return Client::query()
            ->where('workspace_id', Auth::user()->workspace_id)
            ->orderBy('name')
            ->get();
    }

    /**
     * @return Collection<int, Project>
     */
    #[Computed]
    public function projects(): Collection
    {
        return Project::query()
            ->visibleTo(Auth::user())
            ->active()
            ->with('client')
            ->withCount([
                'rootTasks as open_tasks_count' => function ($query) {
                    $query->whereNotIn('status', ['done', 'canceled']);
                },
                'rootTasks as total_tasks_count',
            ])
            ->orderBy('position')
            ->orderBy('name')
            ->get();
    }

    public function createProject(): void
    {
        abort_unless(Auth::user()->isTeam(), 403);

        $validated = $this->validate();

        $workspaceId = Auth::user()->workspace_id;

        $project = Project::create([
            'workspace_id' => $workspaceId,
            'name' => $validated['name'],
            'key' => Project::generateKey($validated['name']),
            'color' => $validated['color'],
            'client_id' => $this->clientId ?: null,
            'description' => $this->description !== '' ? $this->description : null,
            'repo_path' => $this->repoPath !== '' ? $this->repoPath : null,
            'stack' => $this->stack !== '' ? $this->stack : null,
            'context' => $this->context !== '' ? $this->context : null,
            'position' => (int) Project::where('workspace_id', $workspaceId)->max('position') + 1,
        ]);

        // Grant access to the chosen members plus the creator (when they are a
        // member — admins already see every project).
        $memberIds = $this->teamMembers->pluck('id')->intersect($this->memberIds);

        if (Auth::user()->role === UserRole::Member) {
            $memberIds = $memberIds->push(Auth::id());
        }

        $project->members()->sync($memberIds->unique()->all());

        $this->reset('name', 'description', 'repoPath', 'stack', 'context', 'memberIds');
        $this->color = 'blue';

        Flux::modal('create-project')->close();
        Flux::toast(variant: 'success', text: __('Project aangemaakt.'));

        $this->redirectRoute('projects.board', $project, navigate: true);
    }

    public function render(): View
    {
        return view('livewire.projects.index');
    }
}

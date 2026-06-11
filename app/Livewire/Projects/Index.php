<?php

namespace App\Livewire\Projects;

use App\Models\Client;
use App\Models\Project;
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

    public function canManage(): bool
    {
        return Auth::user()->isTeam();
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

        $project = Project::create([
            'name' => $validated['name'],
            'key' => Project::generateKey($validated['name']),
            'color' => $validated['color'],
            'client_id' => $this->clientId ?: null,
            'description' => $this->description !== '' ? $this->description : null,
            'repo_path' => $this->repoPath !== '' ? $this->repoPath : null,
            'stack' => $this->stack !== '' ? $this->stack : null,
            'context' => $this->context !== '' ? $this->context : null,
            'position' => (int) Project::max('position') + 1,
        ]);

        $this->reset('name', 'description', 'repoPath', 'stack', 'context');
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

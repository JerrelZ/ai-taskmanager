<?php

namespace App\Livewire;

use App\Models\Workspace;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Computed;
use Livewire\Component;

/**
 * Lets a user who belongs to more than one workspace switch the active one.
 * Renders nothing for single-workspace users, so it stays invisible until
 * multi-workspace membership actually exists.
 */
class WorkspaceSwitcher extends Component
{
    /**
     * @return Collection<int, Workspace>
     */
    #[Computed]
    public function workspaces(): Collection
    {
        return Auth::user()->workspaces()->orderBy('name')->get();
    }

    #[Computed]
    public function current(): ?Workspace
    {
        return Auth::user()->workspace;
    }

    public function switch(int $workspaceId): void
    {
        if (Auth::user()->switchWorkspace($workspaceId)) {
            $this->redirect(route('tickets.index'), navigate: true);
        }
    }

    public function render(): View
    {
        return view('livewire.workspace-switcher');
    }
}

<?php

namespace App\Livewire\Clients;

use App\Models\Client;
use Flux\Flux;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Attributes\Validate;
use Livewire\Component;

#[Title('Klanten')]
class Index extends Component
{
    #[Validate('required|string|max:255')]
    public string $name = '';

    #[Validate('required|string|max:30')]
    public string $color = 'blue';

    public function mount(): void
    {
        abort_unless(Auth::user()->isAdmin(), 403);
    }

    /**
     * @return Collection<int, Client>
     */
    #[Computed]
    public function clients(): Collection
    {
        return Client::query()
            ->withCount(['projects', 'users'])
            ->orderBy('name')
            ->get();
    }

    public function createClient(): void
    {
        abort_unless(Auth::user()->isAdmin(), 403);

        $validated = $this->validate();

        Client::create($validated);

        $this->reset('name');
        $this->color = 'blue';

        Flux::modal('create-client')->close();
        Flux::toast(variant: 'success', text: __('Klant aangemaakt.'));
    }

    public function render(): View
    {
        return view('livewire.clients.index');
    }
}

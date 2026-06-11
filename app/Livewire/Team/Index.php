<?php

namespace App\Livewire\Team;

use App\Enums\UserRole;
use App\Models\Client;
use App\Models\User;
use Flux\Flux;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;

#[Title('Team')]
class Index extends Component
{
    public string $name = '';

    public string $email = '';

    public string $role = 'member';

    public ?int $clientId = null;

    public string $password = '';

    public function mount(): void
    {
        abort_unless(Auth::user()->isAdmin(), 403);
    }

    /**
     * @return Collection<int, User>
     */
    #[Computed]
    public function users(): Collection
    {
        return User::query()->with('client')->orderBy('name')->get();
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
     * @return array<int, UserRole>
     */
    public function roles(): array
    {
        return UserRole::cases();
    }

    public function createUser(): void
    {
        abort_unless(Auth::user()->isAdmin(), 403);

        $validated = $this->validate([
            'name' => 'required|string|max:255',
            'email' => 'required|email|max:255|unique:users,email',
            'role' => 'required|in:'.implode(',', array_column(UserRole::cases(), 'value')),
            'clientId' => 'nullable|exists:clients,id',
            'password' => 'required|string|min:8',
        ]);

        $role = UserRole::from($validated['role']);

        User::create([
            'name' => $validated['name'],
            'email' => $validated['email'],
            'role' => $role,
            'client_id' => $role === UserRole::Client ? $validated['clientId'] : null,
            'password' => Hash::make($validated['password']),
            'email_verified_at' => now(),
        ]);

        $this->reset('name', 'email', 'password', 'clientId');
        $this->role = 'member';

        Flux::modal('create-user')->close();
        Flux::toast(variant: 'success', text: __('Gebruiker aangemaakt.'));
    }

    public function render(): View
    {
        return view('livewire.team.index');
    }
}

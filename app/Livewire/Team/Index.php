<?php

namespace App\Livewire\Team;

use App\Enums\UserRole;
use App\Mail\InvitationMail;
use App\Models\Client;
use App\Models\Invitation;
use App\Models\User;
use Flux\Flux;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Mail;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;

#[Title('Team')]
class Index extends Component
{
    public string $email = '';

    public string $role = 'member';

    public ?int $clientId = null;

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
        return User::query()
            ->inWorkspace(Auth::user()->workspace_id)
            ->with('client')
            ->orderBy('name')
            ->get();
    }

    /**
     * Outstanding invitations that have not yet been accepted.
     *
     * @return Collection<int, Invitation>
     */
    #[Computed]
    public function invitations(): Collection
    {
        return Invitation::query()
            ->where('workspace_id', Auth::user()->workspace_id)
            ->pending()
            ->with('client')
            ->latest()
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
     * @return array<int, UserRole>
     */
    public function roles(): array
    {
        return UserRole::cases();
    }

    /**
     * Invite someone by e-mail. They set their own name and password from the
     * link, so no temporary credentials ever leave the system.
     */
    public function sendInvite(): void
    {
        abort_unless(Auth::user()->isAdmin(), 403);

        $validated = $this->validate([
            'email' => 'required|email|max:255|unique:users,email',
            'role' => 'required|in:'.implode(',', array_column(UserRole::cases(), 'value')),
            'clientId' => 'nullable|exists:clients,id',
        ]);

        $role = UserRole::from($validated['role']);
        $workspaceId = Auth::user()->workspace_id;

        // Replace any earlier pending invite to the same address so a person
        // never ends up with two live links.
        Invitation::query()
            ->where('workspace_id', $workspaceId)
            ->where('email', $validated['email'])
            ->pending()
            ->delete();

        $invitation = Invitation::create([
            'workspace_id' => $workspaceId,
            'email' => $validated['email'],
            'role' => $role,
            'client_id' => $role === UserRole::Client ? $validated['clientId'] : null,
            'token' => Invitation::generateToken(),
            'invited_by' => Auth::id(),
            'expires_at' => now()->addDays(7),
        ]);

        Mail::to($invitation->email)->send(new InvitationMail($invitation));

        $this->reset('email', 'clientId');
        $this->role = 'member';

        unset($this->invitations);

        Flux::modal('invite-user')->close();
        Flux::toast(variant: 'success', text: __('Uitnodiging verstuurd.'));
    }

    /**
     * Re-send an outstanding invitation, refreshing its expiry.
     */
    public function resendInvitation(int $id): void
    {
        abort_unless(Auth::user()->isAdmin(), 403);

        $invitation = Invitation::query()
            ->where('workspace_id', Auth::user()->workspace_id)
            ->pending()
            ->findOrFail($id);

        $invitation->update(['expires_at' => now()->addDays(7)]);

        Mail::to($invitation->email)->send(new InvitationMail($invitation));

        unset($this->invitations);

        Flux::toast(variant: 'success', text: __('Uitnodiging opnieuw verstuurd.'));
    }

    public function revokeInvitation(int $id): void
    {
        abort_unless(Auth::user()->isAdmin(), 403);

        Invitation::query()
            ->where('workspace_id', Auth::user()->workspace_id)
            ->whereKey($id)
            ->delete();

        unset($this->invitations);

        Flux::toast(text: __('Uitnodiging ingetrokken.'));
    }

    public function render(): View
    {
        return view('livewire.team.index');
    }
}

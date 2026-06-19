<?php

namespace App\Http\Controllers;

use App\Concerns\PasswordValidationRules;
use App\Models\Invitation;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class InvitationController extends Controller
{
    use PasswordValidationRules;

    /**
     * Show the accept-invitation form for a still-valid token.
     */
    public function show(string $token): View
    {
        $invitation = $this->pendingInvitationOrFail($token);

        return view('auth.accept-invitation', [
            'invitation' => $invitation,
            'token' => $token,
        ]);
    }

    /**
     * Create the invited user from their chosen name + password, then log in.
     */
    public function store(Request $request, string $token): RedirectResponse
    {
        $invitation = $this->pendingInvitationOrFail($token);

        // The invited address might have been claimed since the mail went out.
        if (User::where('email', $invitation->email)->exists()) {
            throw ValidationException::withMessages([
                'email' => __('Er bestaat al een account met dit e-mailadres.'),
            ]);
        }

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'password' => $this->passwordRules(),
        ]);

        $user = DB::transaction(function () use ($invitation, $validated): User {
            $user = User::create([
                'workspace_id' => $invitation->workspace_id,
                'name' => $validated['name'],
                'email' => $invitation->email,
                'password' => $validated['password'],
                'role' => $invitation->role,
                'client_id' => $invitation->client_id,
                'email_verified_at' => now(),
            ]);

            $user->workspaces()->syncWithoutDetaching([$invitation->workspace_id]);

            $invitation->forceFill(['accepted_at' => now()])->save();

            return $user;
        });

        Auth::login($user);
        $request->session()->regenerate();

        return redirect()->intended(route('home'));
    }

    /**
     * Resolve a pending invitation or abort with a 404.
     */
    private function pendingInvitationOrFail(string $token): Invitation
    {
        $invitation = Invitation::where('token', $token)->with('workspace')->first();

        abort_if($invitation === null || ! $invitation->isPending(), 404);

        return $invitation;
    }
}

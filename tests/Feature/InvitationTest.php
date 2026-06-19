<?php

use App\Enums\UserRole;
use App\Livewire\Team\Index as TeamIndex;
use App\Mail\InvitationMail;
use App\Models\Client;
use App\Models\Invitation;
use App\Models\User;
use Illuminate\Support\Facades\Mail;
use Livewire\Livewire;

beforeEach(function () {
    $this->admin = User::factory()->admin()->create();
});

test('an admin can invite a user by email', function () {
    Mail::fake();
    $this->actingAs($this->admin);

    Livewire::test(TeamIndex::class)
        ->set('email', 'nieuw@voorbeeld.nl')
        ->set('role', UserRole::Member->value)
        ->call('sendInvite')
        ->assertHasNoErrors();

    $invitation = Invitation::where('email', 'nieuw@voorbeeld.nl')->first();

    expect($invitation)->not->toBeNull()
        ->and($invitation->workspace_id)->toBe($this->admin->workspace_id)
        ->and($invitation->invited_by)->toBe($this->admin->id)
        ->and($invitation->isPending())->toBeTrue();

    Mail::assertQueued(InvitationMail::class);
});

test('inviting an existing email fails validation', function () {
    $this->actingAs($this->admin);
    User::factory()->create(['email' => 'bestaat@voorbeeld.nl']);

    Livewire::test(TeamIndex::class)
        ->set('email', 'bestaat@voorbeeld.nl')
        ->call('sendInvite')
        ->assertHasErrors('email');
});

test('a new invite replaces an earlier pending one for the same email', function () {
    Mail::fake();
    $this->actingAs($this->admin);

    $old = Invitation::factory()->create([
        'workspace_id' => $this->admin->workspace_id,
        'email' => 'dubbel@voorbeeld.nl',
    ]);

    Livewire::test(TeamIndex::class)
        ->set('email', 'dubbel@voorbeeld.nl')
        ->call('sendInvite');

    expect(Invitation::find($old->id))->toBeNull()
        ->and(Invitation::where('email', 'dubbel@voorbeeld.nl')->pending()->count())->toBe(1);
});

test('a non-admin cannot open team management', function () {
    $this->actingAs(User::factory()->create());

    Livewire::test(TeamIndex::class)->assertForbidden();
});

test('an admin can revoke an invitation', function () {
    $this->actingAs($this->admin);

    $invitation = Invitation::factory()->create(['workspace_id' => $this->admin->workspace_id]);

    Livewire::test(TeamIndex::class)->call('revokeInvitation', $invitation->id);

    expect(Invitation::find($invitation->id))->toBeNull();
});

test('the accept page renders for a valid token', function () {
    $invitation = Invitation::factory()->create(['workspace_id' => $this->admin->workspace_id]);

    $this->get(route('invitations.accept', $invitation->token))
        ->assertOk()
        ->assertSee($invitation->email);
});

test('accepting an invitation creates the user, logs them in and marks it accepted', function () {
    $invitation = Invitation::factory()->create([
        'workspace_id' => $this->admin->workspace_id,
        'email' => 'teamlid@voorbeeld.nl',
        'role' => UserRole::Member,
    ]);

    $this->post(route('invitations.store', $invitation->token), [
        'name' => 'Nieuw Teamlid',
        'password' => 'wachtwoord-1234',
        'password_confirmation' => 'wachtwoord-1234',
    ])->assertRedirect(route('home'));

    $user = User::where('email', 'teamlid@voorbeeld.nl')->first();

    expect($user)->not->toBeNull()
        ->and($user->name)->toBe('Nieuw Teamlid')
        ->and($user->role)->toBe(UserRole::Member)
        ->and($user->workspace_id)->toBe($this->admin->workspace_id)
        ->and($user->belongsToWorkspace($this->admin->workspace_id))->toBeTrue();

    $this->assertAuthenticatedAs($user);
    expect($invitation->fresh()->accepted_at)->not->toBeNull();
});

test('accepting a client invitation assigns the client', function () {
    $client = Client::factory()->create(['workspace_id' => $this->admin->workspace_id]);
    $invitation = Invitation::factory()->create([
        'workspace_id' => $this->admin->workspace_id,
        'email' => 'klant@voorbeeld.nl',
        'role' => UserRole::Client,
        'client_id' => $client->id,
    ]);

    $this->post(route('invitations.store', $invitation->token), [
        'name' => 'Klant Persoon',
        'password' => 'wachtwoord-1234',
        'password_confirmation' => 'wachtwoord-1234',
    ]);

    $user = User::where('email', 'klant@voorbeeld.nl')->first();

    expect($user->role)->toBe(UserRole::Client)
        ->and($user->client_id)->toBe($client->id);
});

test('an expired token cannot be accepted', function () {
    $invitation = Invitation::factory()->expired()->create(['workspace_id' => $this->admin->workspace_id]);

    $this->get(route('invitations.accept', $invitation->token))->assertNotFound();
    $this->post(route('invitations.store', $invitation->token), [
        'name' => 'Te Laat',
        'password' => 'wachtwoord-1234',
        'password_confirmation' => 'wachtwoord-1234',
    ])->assertNotFound();
});

test('an already accepted token cannot be reused', function () {
    $invitation = Invitation::factory()->accepted()->create(['workspace_id' => $this->admin->workspace_id]);

    $this->get(route('invitations.accept', $invitation->token))->assertNotFound();
});

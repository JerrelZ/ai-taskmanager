<?php

use App\Livewire\Team\Index as TeamIndex;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Support\Facades\Hash;
use Livewire\Livewire;

beforeEach(function () {
    $this->admin = User::factory()->admin()->create();
});

test('an admin can change a workspace user password', function () {
    $this->actingAs($this->admin);

    $member = User::factory()->create(['workspace_id' => $this->admin->workspace_id]);

    Livewire::test(TeamIndex::class)
        ->call('editPassword', $member->id)
        ->assertSet('passwordUserId', $member->id)
        ->set('password', 'nieuw-geheim-123')
        ->set('password_confirmation', 'nieuw-geheim-123')
        ->call('updateUserPassword')
        ->assertHasNoErrors()
        ->assertSet('passwordUserId', null);

    expect(Hash::check('nieuw-geheim-123', $member->fresh()->password))->toBeTrue();
});

test('the new password must be confirmed', function () {
    $this->actingAs($this->admin);

    $member = User::factory()->create(['workspace_id' => $this->admin->workspace_id]);

    Livewire::test(TeamIndex::class)
        ->call('editPassword', $member->id)
        ->set('password', 'nieuw-geheim-123')
        ->set('password_confirmation', 'klopt-niet')
        ->call('updateUserPassword')
        ->assertHasErrors('password');
});

test('an admin cannot change a password for a user in another workspace', function () {
    $this->actingAs($this->admin);

    $otherWorkspace = Workspace::factory()->create();
    $outsider = User::factory()->create(['workspace_id' => $otherWorkspace->id]);

    expect($outsider->workspace_id)->not->toBe($this->admin->workspace_id);

    $this->expectException(\Illuminate\Database\Eloquent\ModelNotFoundException::class);

    Livewire::test(TeamIndex::class)->call('editPassword', $outsider->id);
});

test('a non-admin cannot access the team page', function () {
    $member = User::factory()->create();
    $this->actingAs($member);

    Livewire::test(TeamIndex::class)->assertStatus(403);
});

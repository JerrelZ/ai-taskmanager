<?php

use App\Actions\Fortify\CreateNewUser;
use App\Livewire\WorkspaceSwitcher;
use App\Models\Project;
use App\Models\User;
use App\Models\Workspace;
use Livewire\Livewire;

it('makes a registrant a member of their new workspace', function () {
    $user = app(CreateNewUser::class)->create([
        'name' => 'Nieuw',
        'email' => 'nieuw@example.com',
        'password' => 'password123',
        'password_confirmation' => 'password123',
    ]);

    expect($user->workspaces)->toHaveCount(1)
        ->and($user->belongsToWorkspace($user->workspace_id))->toBeTrue();
});

it('switches the active workspace only to ones the user belongs to', function () {
    $company = Workspace::factory()->create();
    $personal = Workspace::factory()->create();
    $stranger = Workspace::factory()->create();

    $user = User::factory()->create(['workspace_id' => $company->id]);
    $user->workspaces()->attach($personal);

    expect($user->switchWorkspace($personal))->toBeTrue()
        ->and($user->fresh()->workspace_id)->toBe($personal->id);

    // Not a member of the stranger workspace: refused, active stays put.
    expect($user->switchWorkspace($stranger))->toBeFalse()
        ->and($user->fresh()->workspace_id)->toBe($personal->id);
});

it('switches the active workspace through the switcher component', function () {
    $company = Workspace::factory()->create();
    $personal = Workspace::factory()->create();

    $user = User::factory()->create(['workspace_id' => $company->id]);
    $user->workspaces()->attach($personal);

    Livewire::actingAs($user)
        ->test(WorkspaceSwitcher::class)
        ->call('switch', $personal->id)
        ->assertRedirect(route('tickets.index'));

    expect($user->fresh()->workspace_id)->toBe($personal->id);
});

it('refuses to switch to a workspace the user is not a member of', function () {
    $company = Workspace::factory()->create();
    $stranger = Workspace::factory()->create();

    $user = User::factory()->create(['workspace_id' => $company->id]);

    Livewire::actingAs($user)
        ->test(WorkspaceSwitcher::class)
        ->call('switch', $stranger->id)
        ->assertNoRedirect();

    expect($user->fresh()->workspace_id)->toBe($company->id);
});

it('scopes member lists to the workspace via membership', function () {
    $company = Workspace::factory()->create();
    $personal = Workspace::factory()->create();

    $owner = User::factory()->create(['workspace_id' => $company->id]);
    $owner->workspaces()->attach($personal);
    $teammate = User::factory()->create(['workspace_id' => $company->id]);
    $outsider = User::factory()->create(['workspace_id' => $personal->id]);

    $companyMembers = User::query()->inWorkspace($company->id)->pluck('id');
    expect($companyMembers)->toContain($owner->id, $teammate->id)
        ->and($companyMembers)->not->toContain($outsider->id);

    // The owner belongs to the personal workspace too, the teammate does not.
    $personalMembers = User::query()->inWorkspace($personal->id)->pluck('id');
    expect($personalMembers)->toContain($owner->id, $outsider->id)
        ->and($personalMembers)->not->toContain($teammate->id);
});

it('only shows a private workspace project after switching into it', function () {
    $company = Workspace::factory()->create();
    $personal = Workspace::factory()->create();

    $owner = User::factory()->create(['workspace_id' => $company->id]);
    $owner->workspaces()->attach($personal);

    $personalProject = Project::factory()->create(['workspace_id' => $personal->id]);

    // Active in the company: the personal project is invisible.
    expect($personalProject->isVisibleTo($owner))->toBeFalse();

    // After switching, it becomes visible.
    $owner->switchWorkspace($personal);
    expect($personalProject->isVisibleTo($owner->fresh()))->toBeTrue();
});

it('adds a member to a workspace via the artisan command', function () {
    $user = User::factory()->create();
    $original = $user->workspace_id;

    $this->artisan('workspace:member', ['email' => $user->email, 'workspace' => 'Gedeeld bedrijf'])
        ->assertSuccessful();

    $workspace = Workspace::firstWhere('name', 'Gedeeld bedrijf');

    expect($user->fresh()->belongsToWorkspace($workspace))->toBeTrue()
        // No --active: the active workspace is unchanged.
        ->and($user->fresh()->workspace_id)->toBe($original);

    // With --active it also becomes the active one.
    $this->artisan('workspace:member', ['email' => $user->email, 'workspace' => (string) $workspace->id, '--active' => true])
        ->assertSuccessful();

    expect($user->fresh()->workspace_id)->toBe($workspace->id);
});

<?php

use App\Livewire\Projects\Board;
use App\Livewire\Projects\Index;
use App\Models\Project;
use App\Models\User;
use App\Models\Workspace;
use Livewire\Livewire;

test('a member only sees projects they belong to', function () {
    $workspace = Workspace::factory()->create();
    $member = User::factory()->create(['workspace_id' => $workspace->id]);

    $joined = Project::factory()->create(['workspace_id' => $workspace->id]);
    $joined->members()->attach($member);
    $excluded = Project::factory()->create(['workspace_id' => $workspace->id]);

    $visible = Project::query()->visibleTo($member)->pluck('id');

    expect($visible)->toContain($joined->id)
        ->not->toContain($excluded->id);

    expect($joined->isVisibleTo($member))->toBeTrue()
        ->and($excluded->isVisibleTo($member))->toBeFalse();
});

test('an admin sees every project in the workspace regardless of membership', function () {
    $workspace = Workspace::factory()->create();
    $admin = User::factory()->admin()->create(['workspace_id' => $workspace->id]);

    $project = Project::factory()->create(['workspace_id' => $workspace->id]);

    expect(Project::query()->visibleTo($admin)->pluck('id'))->toContain($project->id)
        ->and($project->isVisibleTo($admin))->toBeTrue();
});

test('a member cannot open the board of a project they are not a member of', function () {
    $member = User::factory()->create();
    $project = Project::factory()->create(['workspace_id' => $member->workspace_id]);

    $this->actingAs($member);

    $this->get(route('projects.board', $project))->assertForbidden();
});

test('creating a project attaches the chosen members and the member creator', function () {
    $creator = User::factory()->create();
    $teammate = User::factory()->create(['workspace_id' => $creator->workspace_id]);
    $this->actingAs($creator);

    Livewire::test(Index::class)
        ->set('name', 'Nieuw project')
        ->set('memberIds', [$teammate->id])
        ->call('createProject')
        ->assertHasNoErrors();

    $project = Project::where('name', 'Nieuw project')->firstOrFail();

    expect($project->members->pluck('id')->all())
        ->toContain($creator->id, $teammate->id);
});

test('an admin creator is not auto-attached but the chosen members are', function () {
    $admin = User::factory()->admin()->create();
    $teammate = User::factory()->create(['workspace_id' => $admin->workspace_id]);
    $this->actingAs($admin);

    Livewire::test(Index::class)
        ->set('name', 'Adminproject')
        ->set('memberIds', [$teammate->id])
        ->call('createProject')
        ->assertHasNoErrors();

    $project = Project::where('name', 'Adminproject')->firstOrFail();

    expect($project->members->pluck('id')->all())
        ->toContain($teammate->id)
        ->not->toContain($admin->id);
});

test('project settings sync the member list', function () {
    $admin = User::factory()->admin()->create();
    $kept = User::factory()->create(['workspace_id' => $admin->workspace_id]);
    $added = User::factory()->create(['workspace_id' => $admin->workspace_id]);
    $this->actingAs($admin);

    $project = Project::factory()->create(['workspace_id' => $admin->workspace_id]);
    $project->members()->attach($kept);

    Livewire::test(Board::class, ['project' => $project])
        ->call('openProjectSettings')
        ->assertSet('memberIds', [$kept->id])
        ->set('memberIds', [$kept->id, $added->id])
        ->call('saveProject')
        ->assertHasNoErrors();

    expect($project->fresh()->members->pluck('id')->all())
        ->toContain($kept->id, $added->id);
});

test('the member sync ignores ids that are not team members of the workspace', function () {
    $admin = User::factory()->admin()->create();
    $outsider = User::factory()->create(['workspace_id' => Workspace::factory()->create()->id]);
    $this->actingAs($admin);

    $project = Project::factory()->create(['workspace_id' => $admin->workspace_id]);

    Livewire::test(Board::class, ['project' => $project])
        ->call('openProjectSettings')
        ->set('memberIds', [$outsider->id])
        ->call('saveProject')
        ->assertHasNoErrors();

    expect($project->fresh()->members)->toBeEmpty();
});

test('accessibleUsers includes admins and members but not excluded members', function () {
    $workspace = Workspace::factory()->create();
    $admin = User::factory()->admin()->create(['workspace_id' => $workspace->id]);
    $member = User::factory()->create(['workspace_id' => $workspace->id]);
    $excluded = User::factory()->create(['workspace_id' => $workspace->id]);

    $project = Project::factory()->create(['workspace_id' => $workspace->id]);
    $project->members()->attach($member);

    $accessible = $project->accessibleUsers()->pluck('id');

    expect($accessible)->toContain($admin->id, $member->id)
        ->not->toContain($excluded->id);
});

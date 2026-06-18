<?php

use App\Models\Client;
use App\Models\Project;
use App\Models\User;
use App\Models\Workspace;

test('a team member only sees projects in their own workspace', function () {
    $workspace = Workspace::factory()->create();
    $teamMember = User::factory()->create(['workspace_id' => $workspace->id]);
    $ownProject = Project::factory()->create(['workspace_id' => $workspace->id]);

    $otherWorkspace = Workspace::factory()->create();
    $foreignProject = Project::factory()->create(['workspace_id' => $otherWorkspace->id]);

    $visible = Project::query()->visibleTo($teamMember)->pluck('id');

    expect($visible)->toContain($ownProject->id)
        ->not->toContain($foreignProject->id);
});

test('a team member cannot open a project board in another workspace', function () {
    $teamMember = User::factory()->create();
    $foreignProject = Project::factory()->create(['workspace_id' => Workspace::factory()->create()->id]);

    $this->actingAs($teamMember);

    $this->get(route('projects.board', $foreignProject))->assertForbidden();
});

test('a client cannot see projects from another workspace even for their own client id', function () {
    // Same client id reused across workspaces must not bleed through.
    $workspace = Workspace::factory()->create();
    $client = Client::factory()->create(['workspace_id' => $workspace->id]);
    $clientUser = User::factory()->client($client)->create(['workspace_id' => $workspace->id]);
    $ownProject = Project::factory()->create(['workspace_id' => $workspace->id, 'client_id' => $client->id]);

    $otherWorkspace = Workspace::factory()->create();
    $foreignProject = Project::factory()->create(['workspace_id' => $otherWorkspace->id, 'client_id' => $client->id]);

    $visible = Project::query()->visibleTo($clientUser)->pluck('id');

    expect($visible)->toContain($ownProject->id)
        ->not->toContain($foreignProject->id);
});

test('isVisibleTo guards across workspace and client boundaries', function () {
    $workspace = Workspace::factory()->create();
    $teamMember = User::factory()->create(['workspace_id' => $workspace->id]);
    $client = Client::factory()->create(['workspace_id' => $workspace->id]);
    $clientUser = User::factory()->client($client)->create(['workspace_id' => $workspace->id]);

    $clientProject = Project::factory()->create(['workspace_id' => $workspace->id, 'client_id' => $client->id]);
    $internalProject = Project::factory()->create(['workspace_id' => $workspace->id, 'client_id' => null]);
    $foreignProject = Project::factory()->create(['workspace_id' => Workspace::factory()->create()->id]);

    expect($clientProject->isVisibleTo($teamMember))->toBeTrue()
        ->and($internalProject->isVisibleTo($teamMember))->toBeTrue()
        ->and($foreignProject->isVisibleTo($teamMember))->toBeFalse()
        ->and($clientProject->isVisibleTo($clientUser))->toBeTrue()
        ->and($internalProject->isVisibleTo($clientUser))->toBeFalse();
});

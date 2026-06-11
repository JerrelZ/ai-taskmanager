<?php

use App\Enums\TaskStatus;
use App\Models\Client;
use App\Models\Project;
use App\Models\Task;
use App\Models\User;

test('guests are redirected to login', function () {
    $this->get(route('tickets.index'))->assertRedirect(route('login'));
    $this->get(route('projects.index'))->assertRedirect(route('login'));
});

test('the root url redirects to the tickets overview', function () {
    $this->get('/')->assertRedirect(route('tickets.index'));
});

test('an authenticated team member can reach the overviews', function () {
    $this->actingAs(User::factory()->create());

    $this->get(route('tickets.index'))->assertOk();
    $this->get(route('projects.index'))->assertOk();
});

test('a client can only open their own project board', function () {
    $client = Client::factory()->create();
    $clientUser = User::factory()->client($client)->create();

    $ownProject = Project::factory()->create(['client_id' => $client->id]);
    $otherProject = Project::factory()->create(['client_id' => null]);

    $this->actingAs($clientUser);

    $this->get(route('projects.board', $ownProject))->assertOk();
    $this->get(route('projects.board', $otherProject))->assertForbidden();
});

test('clients only see their own client tickets in the overview', function () {
    $client = Client::factory()->create();
    $clientUser = User::factory()->client($client)->create();

    $ownProject = Project::factory()->create(['client_id' => $client->id]);
    $otherProject = Project::factory()->create(['client_id' => null]);

    Task::factory()->for($ownProject)->status(TaskStatus::Todo)->create(['title' => 'Eigen ticket']);
    Task::factory()->for($otherProject)->status(TaskStatus::Todo)->create(['title' => 'Ander ticket']);

    $this->actingAs($clientUser);

    $this->get(route('tickets.index'))
        ->assertSee('Eigen ticket')
        ->assertDontSee('Ander ticket');
});

test('only admins can reach client and team management', function () {
    $this->actingAs(User::factory()->create()); // member
    $this->get(route('clients.index'))->assertForbidden();
    $this->get(route('team.index'))->assertForbidden();

    $this->actingAs(User::factory()->admin()->create());
    $this->get(route('clients.index'))->assertOk();
    $this->get(route('team.index'))->assertOk();
});

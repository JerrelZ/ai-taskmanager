<?php

use App\Models\Client;
use App\Models\Project;
use App\Models\Task;
use App\Models\User;
use App\Models\Workspace;

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->actingAs($this->user);
});

test('a ticket url opens the ticket on its project board', function () {
    $project = Project::factory()->create(['key' => 'WEB']);
    $task = Task::factory()->for($project)->create(['title' => 'Inlog knop stuk']);

    $this->get(route('tickets.show', ['identifier' => $task->identifier(), 'slug' => $task->ticketSlug()]))
        ->assertRedirect(route('projects.board', ['project' => $project->id, 'openTask' => $task->id]));
});

test('the identifier resolves case-insensitively and ignores the decorative slug', function () {
    $project = Project::factory()->create(['key' => 'WEB']);
    $task = Task::factory()->for($project)->create();

    $this->get(url('/tickets/'.strtolower($task->identifier()).'/whatever-title'))
        ->assertRedirect(route('projects.board', ['project' => $project->id, 'openTask' => $task->id]));
});

test('an old identifier 301-redirects to the current ticket url after a move', function () {
    $web = Project::factory()->create(['key' => 'WEB']);
    $app = Project::factory()->create(['key' => 'APP']);
    $task = Task::factory()->for($web)->create();
    $oldIdentifier = $task->identifier();

    $task->update(['project_id' => $app->id, 'number' => 99]);

    expect($task->fresh()->previous_identifiers)->toContain($oldIdentifier);

    $this->get(route('tickets.show', ['identifier' => $oldIdentifier]))
        ->assertStatus(301)
        ->assertRedirect($task->fresh()->ticketUrl());
});

test('an unknown identifier returns 404', function () {
    Project::factory()->create(['key' => 'WEB']);

    $this->get('/tickets/WEB-999')->assertNotFound();
});

test('a client cannot resolve a ticket in a project they cannot see', function () {
    $client = Client::factory()->create();
    Project::factory()->create(['client_id' => $client->id, 'key' => 'CLI']);
    $secret = Project::factory()->create(['key' => 'SEC']);
    $task = Task::factory()->for($secret)->create();

    $this->actingAs(User::factory()->client($client)->create());

    $this->get(route('tickets.show', ['identifier' => $task->identifier()]))->assertNotFound();
});

test('a ticket in another workspace is not resolvable', function () {
    $otherWorkspace = Workspace::factory()->create();
    $project = Project::factory()->create(['workspace_id' => $otherWorkspace->id, 'key' => 'OTH']);
    $task = Task::factory()->for($project)->create();

    $this->get(route('tickets.show', ['identifier' => $task->identifier()]))->assertNotFound();
});

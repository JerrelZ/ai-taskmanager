<?php

use App\Mcp\Servers\ProjectDatabaseServer;
use App\Mcp\Tools\CreateProjectTaskTool;
use App\Mcp\Tools\ListProjectsTool;
use App\Mcp\Tools\LookupContactByEmailTool;
use App\Mcp\Tools\QueryProjectDatabaseTool;
use App\Models\EmailAccount;
use App\Models\Project;
use App\Models\Task;

function projectWithExternalDb(string $key = 'REV'): Project
{
    $project = Project::factory()->create(['key' => $key, 'name' => 'Revboost']);

    EmailAccount::factory()->create([
        'project_id' => $project->id,
        'external_db_dsn' => [
            'host' => '127.0.0.1',
            'port' => 3306,
            'database' => 'revboost',
            'username' => 'reader',
            'password' => 'secret',
        ],
    ]);

    return $project;
}

it('lists projects and flags those with an external database', function () {
    $project = projectWithExternalDb();

    ProjectDatabaseServer::tool(ListProjectsTool::class, [])
        ->assertOk()
        ->assertSee($project->name)
        ->assertSee('has_external_database');
});

it('rejects a non read-only query before ever connecting', function () {
    projectWithExternalDb();

    ProjectDatabaseServer::tool(QueryProjectDatabaseTool::class, [
        'project' => 'REV',
        'sql' => 'UPDATE customers SET name = "x"',
    ])->assertHasErrors();
});

it('errors when the project has no external database', function () {
    Project::factory()->create(['key' => 'WEB', 'name' => 'Website']);

    ProjectDatabaseServer::tool(QueryProjectDatabaseTool::class, [
        'project' => 'WEB',
        'sql' => 'SELECT 1',
    ])->assertHasErrors(['Project "Website" has no external database configured.']);
});

it('errors when the project cannot be resolved', function () {
    ProjectDatabaseServer::tool(QueryProjectDatabaseTool::class, [
        'project' => 'does-not-exist',
        'sql' => 'SELECT 1',
    ])->assertHasErrors();
});

it('looks up a contact only on projects with an external database', function () {
    Project::factory()->create(['key' => 'WEB', 'name' => 'Website']);

    ProjectDatabaseServer::tool(LookupContactByEmailTool::class, [
        'project' => 'WEB',
        'email' => 'klant@voorbeeld.nl',
    ])->assertHasErrors();
});

it('creates a follow-up task in the resolved project', function () {
    $project = projectWithExternalDb();

    ProjectDatabaseServer::tool(CreateProjectTaskTool::class, [
        'project' => 'REV',
        'title' => 'Bel klant terug over openstaande factuur',
        'priority' => 'high',
    ])->assertOk()->assertSee('Created task');

    $task = Task::where('project_id', $project->id)->first();
    expect($task)->not->toBeNull();
    expect($task->title)->toBe('Bel klant terug over openstaande factuur');
    expect($task->priority->value)->toBe('high');
});

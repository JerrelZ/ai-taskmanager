<?php

use App\Mcp\Servers\ProjectDatabaseServer;
use App\Mcp\Tools\CreateProjectTaskTool;
use App\Mcp\Tools\ListProjectsTool;
use App\Mcp\Tools\LookupContactByEmailTool;
use App\Models\EmailAccount;
use App\Models\Project;
use App\Models\Task;

function projectWithSupportApi(string $key = 'REV'): Project
{
    $project = Project::factory()->create(['key' => $key, 'name' => 'Revboost']);

    EmailAccount::factory()->create([
        'project_id' => $project->id,
        'external_api_base_url' => 'https://revboost.test/api/internal/v1',
        'external_api_token' => 'secret-token',
    ]);

    return $project;
}

it('lists projects and flags those with a support API', function () {
    $project = projectWithSupportApi();

    ProjectDatabaseServer::tool(ListProjectsTool::class, [])
        ->assertOk()
        ->assertSee($project->name)
        ->assertSee('has_support_api');
});

it('has no raw database access left anywhere', function () {
    expect(file_exists(app_path('Mcp/Tools/QueryProjectDatabaseTool.php')))->toBeFalse();
    expect(file_exists(app_path('Services/Email/ExternalProjectDb.php')))->toBeFalse();
});

it('errors when the project cannot be resolved', function () {
    ProjectDatabaseServer::tool(LookupContactByEmailTool::class, [
        'project' => 'does-not-exist',
        'email' => 'klant@voorbeeld.nl',
    ])->assertHasErrors();
});

it('looks up a contact only on projects with a support API', function () {
    $project = Project::factory()->create(['key' => 'WEB', 'name' => 'Website']);
    EmailAccount::factory()->create(['project_id' => $project->id]);

    ProjectDatabaseServer::tool(LookupContactByEmailTool::class, [
        'project' => 'WEB',
        'email' => 'klant@voorbeeld.nl',
    ])->assertHasErrors(['Project "Website" has no support API configured.']);
});

it('creates a follow-up task in the resolved project', function () {
    $project = projectWithSupportApi();

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

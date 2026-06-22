<?php

use App\Mcp\Servers\ProjectDatabaseServer;
use App\Mcp\Tools\ApiUserLookupTool;
use App\Mcp\Tools\CustomerSummaryTool;
use App\Models\EmailAccount;
use App\Models\Project;
use App\Services\Email\ExternalProjectApi;
use Illuminate\Support\Facades\Http;

function apiAccount(string $key = 'REV'): EmailAccount
{
    $project = Project::factory()->create(['key' => $key, 'name' => 'Revboost']);

    return EmailAccount::factory()->create([
        'project_id' => $project->id,
        'external_api_base_url' => 'https://revboost.test/api/internal/v1',
        'external_api_token' => 'secret-token',
    ]);
}

it('reports whether the support API is configured', function () {
    $configured = apiAccount();
    $plain = EmailAccount::factory()->create();

    $api = app(ExternalProjectApi::class);
    expect($api->configured($configured))->toBeTrue();
    expect($api->configured($plain))->toBeFalse();
});

it('calls the internal API with the bearer token', function () {
    Http::fake([
        'https://revboost.test/api/internal/v1/*' => Http::response(['users' => [['id' => 8, 'email' => 'a@b.nl']]], 200),
    ]);

    $account = apiAccount();
    $users = app(ExternalProjectApi::class)->lookupUserByEmail($account, 'a@b.nl');

    expect($users)->toHaveCount(1);
    expect($users[0]['id'])->toBe(8);

    Http::assertSent(fn ($request) => str_contains($request->url(), '/api/internal/v1/users')
        && $request->hasHeader('Authorization', 'Bearer secret-token'));
});

it('exposes the API user lookup as an MCP tool', function () {
    Http::fake([
        'https://revboost.test/api/internal/v1/*' => Http::response(['users' => [['id' => 8]]], 200),
    ]);

    $account = apiAccount();

    ProjectDatabaseServer::tool(ApiUserLookupTool::class, [
        'project' => 'REV',
        'email' => 'a@b.nl',
    ])->assertOk()->assertSee('user(s) found');
});

it('errors from the summary tool when no API is configured', function () {
    $project = Project::factory()->create(['key' => 'WEB']);
    EmailAccount::factory()->create(['project_id' => $project->id]);

    ProjectDatabaseServer::tool(CustomerSummaryTool::class, [
        'project' => 'WEB',
        'user_id' => '8',
    ])->assertHasErrors();
});

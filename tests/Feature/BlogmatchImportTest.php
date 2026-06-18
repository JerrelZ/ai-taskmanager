<?php

use App\Enums\TaskStatus;
use App\Models\Client;
use App\Models\Project;
use App\Models\Workspace;

/**
 * Load a fresh instance of the Blogmatch import migration.
 */
function blogmatchMigration(): object
{
    return include database_path('migrations/2026_06_18_093440_import_blogmatch_tickets.php');
}

test('importing blogmatch creates the client, project and every ticket', function () {
    Workspace::factory()->create();

    blogmatchMigration()->up();

    $client = Client::firstWhere('name', 'Blogmatch');
    expect($client)->not->toBeNull();

    $project = Project::firstWhere('client_id', $client->id);
    expect($project)->not->toBeNull()
        ->and($project->key)->toBe('BLO')
        ->and($project->tasks()->count())->toBe(203)
        ->and($project->tasks()->whereNotNull('parent_id')->count())->toBe(36)
        ->and($project->tasks()->where('status', TaskStatus::Done->value)->count())->toBe(146);

    // BLO-13 is a subtask of BLO-11: the parent link survives the import.
    $child = $project->tasks()->where('number', 13)->first();
    $parent = $project->tasks()->where('number', 11)->first();
    expect($child->parent_id)->toBe($parent->id);
});

test('the blogmatch import no-ops without a workspace and is idempotent', function () {
    // No workspace: a fresh database (like the test suite default) imports nothing.
    blogmatchMigration()->up();
    expect(Client::where('name', 'Blogmatch')->exists())->toBeFalse();

    // With a workspace it imports exactly once, no matter how often it runs.
    Workspace::factory()->create();
    blogmatchMigration()->up();
    blogmatchMigration()->up();

    expect(Client::where('name', 'Blogmatch')->count())->toBe(1)
        ->and(Project::where('key', 'BLO')->count())->toBe(1);
});

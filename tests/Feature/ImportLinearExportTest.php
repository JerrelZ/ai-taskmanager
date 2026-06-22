<?php

use App\Enums\TaskPriority;
use App\Enums\TaskStatus;
use App\Enums\UserRole;
use App\Models\Client;
use App\Models\Project;
use App\Models\Task;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;

/**
 * Write a minimal Linear-style CSV export to a temp file and return its path.
 *
 * @param  list<array<int, string>>  $rows
 */
function writeLinearCsv(array $rows): string
{
    $header = ['ID', 'Team', 'Title', 'Description', 'Status', 'Estimate', 'Priority', 'Project ID', 'Project', 'Creator', 'Assignee', 'Labels', 'Cycle Number', 'Cycle Name', 'Cycle Start', 'Cycle End', 'Created', 'Updated', 'Started', 'Triaged', 'Completed', 'Canceled', 'Archived', 'Due Date', 'Parent issue', 'Initiatives', 'Project Milestone ID', 'Project Milestone', 'SLA Status', 'UUID', 'Time in status (minutes)', 'Related to', 'Blocked by', 'Duplicate of'];

    $path = tempnam(sys_get_temp_dir(), 'linear').'.csv';
    $handle = fopen($path, 'w');
    fputcsv($handle, $header, ',', '"', '\\');

    foreach ($rows as $row) {
        $full = array_fill(0, count($header), '');
        foreach ($row as $i => $value) {
            $full[$i] = $value;
        }
        fputcsv($handle, $full, ',', '"', '\\');
    }

    fclose($handle);

    return $path;
}

/**
 * @return array<int, string>
 */
function linearRow(string $id, string $team, string $title, string $status, string $priority = 'Medium', string $creator = '', string $assignee = '', string $description = '', string $parent = ''): array
{
    // Index positions: 0 ID, 1 Team, 2 Title, 3 Description, 4 Status, 6 Priority,
    // 9 Creator, 10 Assignee, 16 Created, 17 Updated, 24 Parent issue.
    return [
        0 => $id,
        1 => $team,
        2 => $title,
        3 => $description,
        4 => $status,
        6 => $priority,
        9 => $creator,
        10 => $assignee,
        16 => 'Wed Sep 25 2024 08:57:00 GMT+0000 (GMT+00:00)',
        17 => 'Fri Sep 27 2024 11:03:12 GMT+0000 (GMT+00:00)',
        24 => $parent,
    ];
}

/**
 * Create the import owner in their own workspace, the way the command expects.
 */
function importOwner(): User
{
    $workspace = Workspace::factory()->create();

    return User::factory()->create([
        'workspace_id' => $workspace->id,
        'email' => 'jerrel@zendos.nl',
    ]);
}

beforeEach(function () {
    Storage::fake('local');
});

it('imports every ticket of every team into the owner workspace, including completed ones', function () {
    $owner = importOwner();

    // A second tenant that must stay untouched.
    $otherWorkspace = Workspace::factory()->create();
    User::factory()->create(['workspace_id' => $otherWorkspace->id, 'email' => 'sander@example.com']);

    $path = writeLinearCsv([
        linearRow('REVBOOS-10', 'RevBoost', 'Done werk', 'Done', 'High'),
        linearRow('REVBOOS-11', 'RevBoost', 'Open werk', 'Backlog', 'Urgent'),
        linearRow('BCCV2-1', 'BCC-V2', 'BCC ticket', 'Todo', 'Low'),
        linearRow('BLO-5', 'Blogmatchers', 'Blog ticket', 'Canceled', 'Medium'),
    ]);

    $this->artisan('linear:import', ['file' => $path, '--no-attachments' => true])
        ->assertSuccessful();

    // One client + project per team, all in the owner's workspace, with mapped keys.
    expect(Client::where('workspace_id', $owner->workspace_id)->pluck('name')->sort()->values()->all())
        ->toBe(['BCC', 'Blogmatch', 'RevBoost'])
        ->and(Project::pluck('key')->sort()->values()->all())->toBe(['BCC', 'BLO', 'REV']);

    // Completed tickets are kept.
    expect(Task::count())->toBe(4)
        ->and(Project::firstWhere('key', 'REV')->tasks()->count())->toBe(2);

    // The original Linear identifier is kept so the ticket can be traced back.
    expect(Task::where('linear_id', 'BCCV2-1')->exists())->toBeTrue();

    // Nothing leaks into the other tenant, and no users are created from the CSV.
    expect(Client::where('workspace_id', $otherWorkspace->id)->exists())->toBeFalse()
        ->and(User::count())->toBe(2);
});

it('leaves creator and assignee unset so the import never creates users', function () {
    importOwner();

    $path = writeLinearCsv([
        linearRow('REVBOOS-10', 'RevBoost', 'Werk', 'Backlog', 'Urgent', 'someone@linear.app', 'other@linear.app'),
    ]);

    $this->artisan('linear:import', ['file' => $path, '--no-attachments' => true])
        ->assertSuccessful();

    $task = Task::first();

    expect($task->created_by)->toBeNull()
        ->and($task->assignee_id)->toBeNull()
        ->and($task->status)->toBe(TaskStatus::Backlog)
        ->and($task->priority)->toBe(TaskPriority::Urgent)
        ->and(User::where('email', 'someone@linear.app')->exists())->toBeFalse();
});

it('creates missing users with a generated password and attributes tickets when --create-users is set', function () {
    $owner = importOwner();

    $path = writeLinearCsv([
        linearRow('REVBOOS-10', 'RevBoost', 'Werk', 'Backlog', 'Urgent', 'jerrel@zendos.nl', 'nieuw.collega@example.com'),
        linearRow('REVBOOS-11', 'RevBoost', 'Meer werk', 'Todo', 'High', 'nieuw.collega@example.com', ''),
    ]);

    $this->artisan('linear:import', ['file' => $path, '--no-attachments' => true, '--create-users' => true])
        ->assertSuccessful();

    $colleague = User::firstWhere('email', 'nieuw.collega@example.com');

    // Missing assignee/creator is created as a workspace member, the owner is reused.
    expect($colleague)->not->toBeNull()
        ->and($colleague->name)->toBe('Nieuw Collega')
        ->and($colleague->role)->toBe(UserRole::Member)
        ->and($colleague->workspace_id)->toBe($owner->workspace_id)
        ->and($colleague->belongsToWorkspace($owner->workspace_id))->toBeTrue()
        ->and(User::count())->toBe(2);

    // Tickets are attributed to the resolved creator and assignee.
    $first = Task::where('number', 10)->first();
    $second = Task::where('number', 11)->first();

    expect($first->created_by)->toBe($owner->id)
        ->and($first->assignee_id)->toBe($colleague->id)
        ->and($second->created_by)->toBe($colleague->id)
        ->and($second->assignee_id)->toBeNull();
});

it('does not regenerate passwords for users that already exist', function () {
    $owner = importOwner();

    $existing = User::factory()->create([
        'workspace_id' => $owner->workspace_id,
        'email' => 'bestaand@example.com',
        'password' => Hash::make('original-secret'),
    ]);

    $path = writeLinearCsv([
        linearRow('REVBOOS-10', 'RevBoost', 'Werk', 'Backlog', 'High', 'bestaand@example.com', 'bestaand@example.com'),
    ]);

    $this->artisan('linear:import', ['file' => $path, '--no-attachments' => true, '--create-users' => true])
        ->assertSuccessful();

    expect(User::count())->toBe(2)
        ->and(Hash::check('original-secret', $existing->fresh()->password))->toBeTrue()
        ->and(Task::first()->created_by)->toBe($existing->id);
});

it('links subtasks to their parent and preserves timestamps and html', function () {
    importOwner();

    $path = writeLinearCsv([
        linearRow('REVBOOS-10', 'RevBoost', 'Parent', 'Backlog', 'High', description: "- een\n- twee"),
        linearRow('REVBOOS-11', 'RevBoost', 'Child', 'Todo', 'Low', parent: 'REVBOOS-10'),
    ]);

    $this->artisan('linear:import', ['file' => $path, '--no-attachments' => true])
        ->assertSuccessful();

    $parent = Task::where('number', 10)->first();
    $child = Task::where('number', 11)->first();

    expect($child->parent_id)->toBe($parent->id)
        ->and($parent->created_at->toDateString())->toBe('2024-09-25')
        ->and($parent->description)->toContain('<ul>')
        ->and($parent->description)->toContain('<li>');
});

it('downloads attachments and attributes them to the owner', function () {
    $owner = importOwner();

    Http::fake([
        'uploads.linear.app/*' => Http::response('PNGDATA', 200, ['Content-Type' => 'image/png']),
    ]);

    $description = "Zie screenshot\n\n![image.png](https://uploads.linear.app/abc/def/ghi?signature=xyz)";

    $path = writeLinearCsv([
        linearRow('REVBOOS-10', 'RevBoost', 'Met bijlage', 'Backlog', 'High', description: $description),
    ]);

    $this->artisan('linear:import', ['file' => $path])
        ->assertSuccessful();

    $attachment = Task::first()->attachments->first();

    expect($attachment->filename)->toBe('image.png')
        ->and($attachment->mime_type)->toBe('image/png')
        ->and($attachment->uploaded_by)->toBe($owner->id);
    Storage::disk('local')->assertExists($attachment->path);
});

it('is idempotent: re-running skips a team whose client already exists', function () {
    importOwner();

    $path = writeLinearCsv([
        linearRow('REVBOOS-10', 'RevBoost', 'Werk', 'Backlog', 'High'),
    ]);

    $this->artisan('linear:import', ['file' => $path, '--no-attachments' => true])->assertSuccessful();
    $this->artisan('linear:import', ['file' => $path, '--no-attachments' => true])->assertSuccessful();

    expect(Client::where('name', 'RevBoost')->count())->toBe(1)
        ->and(Project::where('key', 'REV')->count())->toBe(1)
        ->and(Task::count())->toBe(1);
});

it('fails when the owner does not exist', function () {
    $path = writeLinearCsv([
        linearRow('REVBOOS-10', 'RevBoost', 'Werk', 'Backlog', 'High'),
    ]);

    $this->artisan('linear:import', ['file' => $path, '--no-attachments' => true])
        ->assertFailed();

    expect(Task::count())->toBe(0);
});

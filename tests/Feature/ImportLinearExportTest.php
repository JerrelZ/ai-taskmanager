<?php

use App\Enums\TaskPriority;
use App\Enums\TaskStatus;
use App\Enums\UserRole;
use App\Models\Project;
use App\Models\Task;
use App\Models\User;
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
function linearRow(string $id, string $title, string $status, string $priority, string $creator, string $assignee = '', string $description = ''): array
{
    // Index positions: 0 ID, 2 Title, 3 Description, 4 Status, 6 Priority, 9 Creator, 10 Assignee, 16 Created, 17 Updated
    return [
        0 => $id,
        1 => 'RevBoost',
        2 => $title,
        3 => $description,
        4 => $status,
        6 => $priority,
        9 => $creator,
        10 => $assignee,
        16 => 'Wed Sep 25 2024 08:57:00 GMT+0000 (GMT+00:00)',
        17 => 'Fri Sep 27 2024 11:03:12 GMT+0000 (GMT+00:00)',
    ];
}

beforeEach(function () {
    Storage::fake('local');
});

it('imports only open REVBOOS tickets, skipping completed and other teams', function () {
    $path = writeLinearCsv([
        linearRow('REVBOOS-10', 'Done ticket', 'Done', 'High', 'jerrel@zendos.nl'),
        linearRow('REVBOOS-20', 'Canceled ticket', 'Canceled', 'Low', 'jerrel@zendos.nl'),
        linearRow('REVBOOS-30', 'Open backlog', 'Backlog', 'Urgent', 'jerrel@zendos.nl', 'sander@gmail.com'),
        linearRow('REVBOOS-31', 'In progress', 'In Progress', 'Medium', 'jerrel@zendos.nl'),
        linearRow('OTHER-99', 'Different team', 'Todo', 'High', 'jerrel@zendos.nl'),
    ]);

    $this->artisan('linear:import', ['file' => $path, '--force' => true, '--no-attachments' => true])
        ->assertSuccessful();

    expect(Task::count())->toBe(2);
    expect(Task::where('number', 30)->value('title'))->toBe('Open backlog');
    expect(Task::pluck('number')->sort()->values()->all())->toBe([30, 31]);
});

it('makes the admin email user 1 and maps roles, status and priority', function () {
    $path = writeLinearCsv([
        linearRow('REVBOOS-30', 'Open backlog', 'Backlog', 'Urgent', 'jerrel@zendos.nl', 'sander@gmail.com'),
    ]);

    $this->artisan('linear:import', ['file' => $path, '--force' => true, '--no-attachments' => true])
        ->assertSuccessful();

    $admin = User::find(1);
    expect($admin->email)->toBe('jerrel@zendos.nl');
    expect($admin->role)->toBe(UserRole::Admin);

    $sander = User::where('email', 'sander@gmail.com')->first();
    expect($sander->role)->toBe(UserRole::Member);
    expect($sander->name)->toBe('Sander');

    $task = Task::where('number', 30)->first();
    expect($task->status)->toBe(TaskStatus::Backlog);
    expect($task->priority)->toBe(TaskPriority::Urgent);
    expect($task->created_by)->toBe($admin->id);
    expect($task->assignee_id)->toBe($sander->id);
    expect($task->project->key)->toBe('REVBOOS');
    expect($task->identifier())->toBe('REVBOOS-30');
});

it('preserves the original created_at timestamp', function () {
    $path = writeLinearCsv([
        linearRow('REVBOOS-30', 'Open backlog', 'Backlog', 'Urgent', 'jerrel@zendos.nl'),
    ]);

    $this->artisan('linear:import', ['file' => $path, '--force' => true, '--no-attachments' => true])
        ->assertSuccessful();

    expect(Task::first()->created_at->toDateString())->toBe('2024-09-25');
});

it('downloads attachments referenced in the description', function () {
    Http::fake([
        'uploads.linear.app/*' => Http::response('PNGDATA', 200, ['Content-Type' => 'image/png']),
    ]);

    $description = "Zie screenshot\n\n![image.png](https://uploads.linear.app/abc/def/ghi?signature=xyz)";

    $path = writeLinearCsv([
        linearRow('REVBOOS-30', 'Met bijlage', 'Backlog', 'High', 'jerrel@zendos.nl', '', $description),
    ]);

    $this->artisan('linear:import', ['file' => $path, '--force' => true])
        ->assertSuccessful();

    $task = Task::first();
    expect($task->attachments)->toHaveCount(1);

    $attachment = $task->attachments->first();
    expect($attachment->filename)->toBe('image.png');
    expect($attachment->mime_type)->toBe('image/png');
    Storage::disk('local')->assertExists($attachment->path);
});

it('wipes existing data before importing', function () {
    User::factory()->count(3)->create();
    Project::factory()->create();

    $path = writeLinearCsv([
        linearRow('REVBOOS-30', 'Open backlog', 'Backlog', 'Urgent', 'jerrel@zendos.nl'),
    ]);

    $this->artisan('linear:import', ['file' => $path, '--force' => true, '--no-attachments' => true])
        ->assertSuccessful();

    expect(User::count())->toBe(1);
    expect(Project::count())->toBe(1);
});

<?php

use App\Enums\TaskStatus;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\Project;
use App\Models\Task;
use App\Models\User;
use App\Notifications\DailyRecapNotification;
use App\Support\DailyRecap;
use Illuminate\Mail\Markdown;
use Illuminate\Support\Facades\Notification;

beforeEach(function () {
    $this->project = Project::factory()->create();
});

function assignedTask(User $user, array $attributes = []): Task
{
    return Task::factory()
        ->for(test()->project)
        ->status(TaskStatus::Todo)
        ->create(array_merge(['assignee_id' => $user->id, 'due_date' => null], $attributes));
}

test('a user with a looming deadline receives a recap', function () {
    Notification::fake();

    $user = User::factory()->create();
    assignedTask($user, ['due_date' => now()->addDay()->format('Y-m-d')]);

    $this->artisan('recap:send-daily')->assertSuccessful();

    Notification::assertSentTo($user, DailyRecapNotification::class);
});

test('a user is not sent two recaps on the same day', function () {
    Notification::fake();

    $user = User::factory()->create();
    assignedTask($user, ['due_date' => now()->subDay()->format('Y-m-d')]);

    $this->artisan('recap:send-daily')->assertSuccessful();
    $this->artisan('recap:send-daily')->assertSuccessful();

    Notification::assertSentToTimes($user, DailyRecapNotification::class, 1);
});

test('the force flag resends even after a recap went out today', function () {
    Notification::fake();

    $user = User::factory()->create();
    assignedTask($user, ['due_date' => now()->subDay()->format('Y-m-d')]);

    $this->artisan('recap:send-daily')->assertSuccessful();
    $this->artisan('recap:send-daily', ['--force' => true])->assertSuccessful();

    Notification::assertSentToTimes($user, DailyRecapNotification::class, 2);
});

test('the recap previews the actual unread chat messages', function () {
    $user = User::factory()->create();
    $partner = User::factory()->create(['name' => 'Sven Partner']);

    $conversation = Conversation::factory()->dm()->create();
    $conversation->users()->attach([
        $user->id => ['last_read_at' => null],
        $partner->id => ['last_read_at' => now()],
    ]);
    Message::factory()->for($conversation)->create([
        'user_id' => $partner->id,
        'body' => 'Kun je dit vandaag nog oppakken?',
    ]);

    $recap = DailyRecap::for($user);
    $mail = (new DailyRecapNotification($recap))->toMail($user);
    $html = (string) app(Markdown::class)->render($mail->markdown, $mail->data());

    expect($recap['unreadMessages'])->toBe(1)
        ->and($html)->toContain('Sven Partner')
        ->and($html)->toContain('Kun je dit vandaag nog oppakken?');
});

test('a recap is sent when others acted on the users tasks today', function () {
    Notification::fake();

    $user = User::factory()->create();
    $other = User::factory()->create();
    $task = assignedTask($user);
    $task->activities()->create(['user_id' => $other->id, 'type' => 'comment']);

    $this->artisan('recap:send-daily')->assertSuccessful();

    Notification::assertSentTo($user, DailyRecapNotification::class);
});

test('a user with open tasks but no activity is skipped', function () {
    Notification::fake();

    $user = User::factory()->create();
    assignedTask($user); // open task, no deadline, no activity, no unread chat

    $this->artisan('recap:send-daily')->assertSuccessful();

    Notification::assertNotSentTo($user, DailyRecapNotification::class);
});

test('the users own activity does not trigger a recap', function () {
    Notification::fake();

    $user = User::factory()->create();
    $task = assignedTask($user);
    $task->activities()->create(['user_id' => $user->id, 'type' => 'status', 'properties' => ['to' => 'In Progress']]);

    $this->artisan('recap:send-daily')->assertSuccessful();

    Notification::assertNotSentTo($user, DailyRecapNotification::class);
});

test('opted-out users never receive a recap', function () {
    Notification::fake();

    $user = User::factory()->create(['daily_recap_enabled' => false]);
    assignedTask($user, ['due_date' => now()->subDay()->format('Y-m-d')]);

    $this->artisan('recap:send-daily')->assertSuccessful();

    Notification::assertNotSentTo($user, DailyRecapNotification::class);
});

test('the recap email renders with the users data', function () {
    $user = User::factory()->create();
    $task = assignedTask($user, ['due_date' => now()->subDay()->format('Y-m-d')]);

    $mail = (new DailyRecapNotification(DailyRecap::for($user)))->toMail($user);
    $html = (string) app(Markdown::class)->render($mail->markdown, $mail->data());

    expect($html)
        ->toContain('dagelijkse overzicht')
        ->toContain($task->title)
        ->toContain('Verlopen op');
});

test('the recap email shows at most five tasks per list', function () {
    $user = User::factory()->create();
    foreach (range(1, 7) as $i) {
        assignedTask($user);
    }

    $mail = (new DailyRecapNotification(DailyRecap::for($user)))->toMail($user);
    $html = (string) app(Markdown::class)->render($mail->markdown, $mail->data());

    $shownTitles = Task::where('assignee_id', $user->id)->get()
        ->filter(fn (Task $task) => str_contains($html, $task->title))
        ->count();

    expect($shownTitles)->toBe(5)
        ->and($html)->toContain('en nog 2 open tickets');
});

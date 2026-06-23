<?php

use App\Enums\MessengerNotificationMode;
use App\Livewire\Tasks\TaskDetail;
use App\Models\Conversation;
use App\Models\Project;
use App\Models\Task;
use App\Models\User;
use App\Notifications\MentionNotification;
use App\Notifications\NewMessageNotification;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;

beforeEach(function () {
    $this->user = User::factory()->create(['name' => 'Auteur Persoon']);
    $this->actingAs($this->user);
});

test('mentioning a participant in chat sends a mention notification instead of a message one', function () {
    Notification::fake();

    $mentioned = User::factory()->create(['name' => 'Sanne de Vries']);

    $group = Conversation::factory()->create();
    $group->users()->sync([$this->user->id, $mentioned->id]);

    $group->postMessage($this->user, 'Hoi @Sanne de Vries kun je dit oppakken?');

    Notification::assertSentTo($mentioned, MentionNotification::class);
    Notification::assertNotSentTo($mentioned, NewMessageNotification::class);
});

test('other participants still receive the regular message notification', function () {
    Notification::fake();

    $mentioned = User::factory()->create(['name' => 'Sanne de Vries']);
    $bystander = User::factory()->create(['name' => 'Bram Jansen']);

    $group = Conversation::factory()->create();
    $group->users()->sync([$this->user->id, $mentioned->id, $bystander->id]);

    $group->postMessage($this->user, 'Hoi @Sanne de Vries kun je dit oppakken?');

    Notification::assertSentTo($bystander, NewMessageNotification::class);
    Notification::assertNotSentTo($bystander, MentionNotification::class);
});

test('a chat mention notifies even a digest user', function () {
    Notification::fake();

    // A direct mention bypasses the messenger preference: a digest user who would
    // normally not get realtime pings is still notified when addressed directly.
    $mentioned = User::factory()->create([
        'name' => 'Sanne de Vries',
        'messenger_notification_mode' => MessengerNotificationMode::Digest,
    ]);

    $group = Conversation::factory()->create();
    $group->users()->sync([$this->user->id, $mentioned->id]);

    $group->postMessage($this->user, 'Hoi @Sanne de Vries');

    Notification::assertSentTo($mentioned, MentionNotification::class);
});

test('mentioning a teammate in a comment notifies them', function () {
    Notification::fake();

    $project = Project::factory()->create();
    $task = Task::factory()->for($project)->create();
    $mentioned = User::factory()->create(['name' => 'Sanne de Vries']);

    Livewire::test(TaskDetail::class)
        ->call('open', $task->id)
        ->set('newComment', 'Goede vraag @Sanne de Vries, wat denk jij?')
        ->call('addComment');

    Notification::assertSentTo($mentioned, MentionNotification::class);
});

test('a comment mention notifies even someone with messenger notifications off', function () {
    Notification::fake();

    // Mentions are direct, so they reach the recipient in-app regardless of their
    // messenger-notification preference; only project visibility gates them.
    $project = Project::factory()->create();
    $task = Task::factory()->for($project)->create();
    $mentioned = User::factory()->create([
        'name' => 'Sanne de Vries',
        'messenger_notifications_enabled' => false,
    ]);

    Livewire::test(TaskDetail::class)
        ->call('open', $task->id)
        ->set('newComment', 'Hoi @Sanne de Vries')
        ->call('addComment');

    Notification::assertSentTo($mentioned, MentionNotification::class);
});

test('a comment mention does not notify the author', function () {
    Notification::fake();

    $project = Project::factory()->create();
    $task = Task::factory()->for($project)->create();

    Livewire::test(TaskDetail::class)
        ->call('open', $task->id)
        ->set('newComment', 'Even een memo aan @Auteur Persoon zelf')
        ->call('addComment');

    Notification::assertNothingSent();
});

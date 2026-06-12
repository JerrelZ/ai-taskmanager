<?php

use App\Enums\ConversationType;
use App\Livewire\Messages\Index;
use App\Models\Client;
use App\Models\Conversation;
use App\Models\Project;
use App\Models\Task;
use App\Models\User;
use Livewire\Livewire;

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->actingAs($this->user);
});

test('the messenger lists conversations the user is a member of', function () {
    $group = Conversation::factory()->create(['name' => 'Algemeen']);
    $group->users()->sync([$this->user->id]);

    $otherGroup = Conversation::factory()->create(['name' => 'Geheim']);
    $otherGroup->users()->sync([User::factory()->create()->id]);

    Livewire::test(Index::class)
        ->assertSee('Algemeen')
        ->assertDontSee('Geheim');
});

test('a message can be sent in a conversation', function () {
    $group = Conversation::factory()->create();
    $group->users()->sync([$this->user->id]);

    Livewire::test(Index::class)
        ->call('openConversation', $group->id)
        ->set('body', 'Hoi allemaal')
        ->call('send')
        ->assertSet('body', '');

    expect($group->messages()->count())->toBe(1)
        ->and($group->fresh()->last_message_at)->not->toBeNull();
});

test('starting a DM reuses an existing one', function () {
    $other = User::factory()->create();

    $component = Livewire::test(Index::class)
        ->set('newDmUserId', $other->id)
        ->call('startDm');

    expect(Conversation::where('type', ConversationType::Dm->value)->count())->toBe(1);

    $component->set('newDmUserId', $other->id)->call('startDm');

    expect(Conversation::where('type', ConversationType::Dm->value)->count())->toBe(1);
});

test('a group conversation can be created with members', function () {
    $a = User::factory()->create();
    $b = User::factory()->create();

    Livewire::test(Index::class)
        ->set('newGroupName', 'Design')
        ->set('newGroupMembers', [$a->id, $b->id])
        ->call('createGroup');

    $group = Conversation::where('name', 'Design')->first();

    expect($group)->not->toBeNull()
        ->and($group->users()->count())->toBe(3);
});

test('a message can be turned into a ticket (fallback without AI key)', function () {
    config()->set('services.anthropic.key', null);

    $project = Project::factory()->create();
    $group = Conversation::factory()->create();
    $group->users()->sync([$this->user->id]);
    $message = $group->postMessage($this->user, 'We moeten de checkout-bug fixen voor de livegang');

    Livewire::test(Index::class)
        ->call('openConversation', $group->id)
        ->call('openTicketDraft', $message->id)
        ->set('ticketProjectId', $project->id)
        ->call('createTicketFromMessage');

    $task = Task::where('project_id', $project->id)->first();

    expect($task)->not->toBeNull()
        ->and($task->description)->toContain('checkout-bug');
});

test('the unread count reflects unread messages and resets on read', function () {
    $other = User::factory()->create();
    $group = Conversation::factory()->create();
    $group->users()->sync([$this->user->id, $other->id]);

    $group->postMessage($other, 'ongelezen bericht');

    expect($this->user->unreadMessagesCount())->toBe(1);

    $group->markReadFor($this->user);

    expect($this->user->fresh()->unreadMessagesCount())->toBe(0);
});

test('clients only see their own project channel', function () {
    $client = Client::factory()->create();
    $clientUser = User::factory()->client($client)->create();

    $ownProject = Project::factory()->create(['client_id' => $client->id]);
    $ownProject->channel()->postMessage($this->user, 'Welkom bij je project');

    $otherProject = Project::factory()->create(['client_id' => null]);
    $otherProject->channel()->postMessage($this->user, 'Interne chat');

    $this->actingAs($clientUser);

    Livewire::test(Index::class)
        ->assertSee($ownProject->name)
        ->assertDontSee($otherProject->name);
});

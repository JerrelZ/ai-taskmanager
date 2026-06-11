<?php

use App\Livewire\Projects\Chat;
use App\Models\Client;
use App\Models\Project;
use App\Models\User;
use Livewire\Livewire;

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->actingAs($this->user);
    $this->project = Project::factory()->create();
});

test('the project chat shows messages from its channel only', function () {
    $this->project->channel()->postMessage($this->user, 'Hallo team');

    $other = Project::factory()->create();
    $other->channel()->postMessage($this->user, 'Ander project bericht');

    Livewire::test(Chat::class, ['project' => $this->project])
        ->assertSee('Hallo team')
        ->assertDontSee('Ander project bericht');
});

test('a message can be posted to the project channel', function () {
    Livewire::test(Chat::class, ['project' => $this->project])
        ->set('body', 'Eerste bericht')
        ->call('send')
        ->assertSet('body', '');

    $channel = $this->project->channel();
    expect($channel->messages()->count())->toBe(1)
        ->and($channel->messages()->first()->user_id)->toBe($this->user->id);
});

test('a blank message is not posted', function () {
    Livewire::test(Chat::class, ['project' => $this->project])
        ->set('body', '   ')
        ->call('send');

    expect($this->project->channel()->messages()->count())->toBe(0);
});

test('a client can chat in their own project', function () {
    $client = Client::factory()->create();
    $clientUser = User::factory()->client($client)->create();
    $project = Project::factory()->create(['client_id' => $client->id]);

    $this->actingAs($clientUser);

    Livewire::test(Chat::class, ['project' => $project])
        ->set('body', 'Vraag van de klant')
        ->call('send');

    expect($project->channel()->messages()->where('user_id', $clientUser->id)->count())->toBe(1);
});

test('a client cannot open the chat of another project', function () {
    $client = Client::factory()->create();
    $clientUser = User::factory()->client($client)->create();
    $otherProject = Project::factory()->create(['client_id' => null]);

    $this->actingAs($clientUser);

    Livewire::test(Chat::class, ['project' => $otherProject])
        ->assertForbidden();
});

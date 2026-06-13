<?php

use App\Livewire\Email\Inbox;
use App\Models\EmailAccount;
use App\Models\Project;
use App\Models\User;
use Livewire\Livewire;

beforeEach(function () {
    $this->actingAs(User::factory()->create());
    $this->project = Project::factory()->create();
});

it('saves external database and API settings from the form', function () {
    $account = EmailAccount::factory()->create(['project_id' => $this->project->id]);

    Livewire::test(Inbox::class, ['project' => $this->project])
        ->call('openSettings')
        ->set('dbHost', '127.0.0.1')
        ->set('dbPort', 3325)
        ->set('dbDatabase', 'forge')
        ->set('dbUsername', 'reader')
        ->set('dbPassword', 'db-secret')
        ->set('apiBaseUrl', 'https://boltool.test')
        ->set('apiToken', '1|plain-token')
        ->call('saveAccount')
        ->assertHasNoErrors();

    $account->refresh();
    expect($account->external_db_dsn['database'])->toBe('forge');
    expect($account->external_db_dsn['password'])->toBe('db-secret');
    expect($account->external_api_base_url)->toBe('https://boltool.test');
    expect($account->external_api_token)->toBe('1|plain-token');
});

it('keeps the stored db password and api token when left blank on edit', function () {
    $account = EmailAccount::factory()->create([
        'project_id' => $this->project->id,
        'external_db_dsn' => ['host' => '127.0.0.1', 'port' => 3325, 'database' => 'forge', 'username' => 'reader', 'password' => 'kept'],
        'external_api_base_url' => 'https://boltool.test',
        'external_api_token' => 'kept-token',
    ]);

    Livewire::test(Inbox::class, ['project' => $this->project])
        ->call('openSettings')
        ->assertSet('dbDatabase', 'forge')
        ->assertSet('dbPassword', '')   // never pre-filled
        ->assertSet('apiToken', '')
        ->set('dbDatabase', 'forge2')
        ->call('saveAccount')
        ->assertHasNoErrors();

    $account->refresh();
    expect($account->external_db_dsn['database'])->toBe('forge2');
    expect($account->external_db_dsn['password'])->toBe('kept');
    expect($account->external_api_token)->toBe('kept-token');
});

it('stores the token encrypted at rest', function () {
    $account = EmailAccount::factory()->create(['project_id' => $this->project->id]);

    Livewire::test(Inbox::class, ['project' => $this->project])
        ->call('openSettings')
        ->set('apiBaseUrl', 'https://boltool.test')
        ->set('apiToken', 'super-secret-token')
        ->call('saveAccount');

    $raw = \Illuminate\Support\Facades\DB::table('email_accounts')->where('id', $account->id)->value('external_api_token');
    expect($raw)->not->toContain('super-secret-token');
});

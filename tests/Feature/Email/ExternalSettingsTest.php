<?php

use App\Livewire\Email\Inbox;
use App\Models\EmailAccount;
use App\Models\Project;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Livewire\Livewire;

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->actingAs($this->user);
    $this->project = Project::factory()->create();
    $this->project->members()->attach($this->user);
});

it('saves the support API settings from the form', function () {
    $account = EmailAccount::factory()->create(['project_id' => $this->project->id]);

    Livewire::test(Inbox::class, ['project' => $this->project])
        ->call('openSettings')
        ->set('apiBaseUrl', 'https://boltool.test')
        ->set('apiToken', '1|plain-token')
        ->call('saveAccount')
        ->assertHasNoErrors();

    $account->refresh();
    expect($account->external_api_base_url)->toBe('https://boltool.test');
    expect($account->external_api_token)->toBe('1|plain-token');
});

it('keeps the stored api token when left blank on edit', function () {
    $account = EmailAccount::factory()->create([
        'project_id' => $this->project->id,
        'external_api_base_url' => 'https://boltool.test',
        'external_api_token' => 'kept-token',
    ]);

    Livewire::test(Inbox::class, ['project' => $this->project])
        ->call('openSettings')
        ->assertSet('apiBaseUrl', 'https://boltool.test')
        ->assertSet('apiToken', '')   // never pre-filled
        ->set('apiBaseUrl', 'https://boltool.test/api/internal/v1')
        ->call('saveAccount')
        ->assertHasNoErrors();

    $account->refresh();
    expect($account->external_api_base_url)->toBe('https://boltool.test/api/internal/v1');
    expect($account->external_api_token)->toBe('kept-token');
});

it('stores the token encrypted at rest', function () {
    $account = EmailAccount::factory()->create(['project_id' => $this->project->id]);

    Livewire::test(Inbox::class, ['project' => $this->project])
        ->call('openSettings')
        ->set('apiBaseUrl', 'https://boltool.test')
        ->set('apiToken', 'super-secret-token')
        ->call('saveAccount');

    $raw = DB::table('email_accounts')->where('id', $account->id)->value('external_api_token');
    expect($raw)->not->toContain('super-secret-token');
});

it('no longer stores database credentials at all', function () {
    expect(Schema::hasColumn('email_accounts', 'external_db_dsn'))->toBeFalse();

    Livewire::test(Inbox::class, ['project' => $this->project])
        ->call('openSettings')
        ->assertDontSee('DB-wachtwoord');
});

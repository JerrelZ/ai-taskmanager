<?php

use App\Enums\UserRole;
use App\Models\Client;
use App\Models\EmailAccount;
use App\Models\EmailFolder;
use App\Models\EmailMessage;
use App\Models\EmailThread;
use App\Models\Project;
use App\Models\User;
use Illuminate\Support\Facades\DB;

it('encrypts the imap password at rest and decrypts via the cast', function () {
    $account = EmailAccount::factory()->create(['password' => 'super-secret']);

    expect($account->fresh()->password)->toBe('super-secret');

    $raw = DB::table('email_accounts')->where('id', $account->id)->value('password');

    expect($raw)->not->toBe('super-secret');
    // The `encrypted` cast stores scalars without serialization, so decrypt with unserialize=false.
    expect(decrypt($raw, false))->toBe('super-secret');
});

it('encrypts the support api token', function () {
    $account = EmailAccount::factory()->create(['external_api_token' => '1|super-secret-token']);

    expect($account->fresh()->external_api_token)->toBe('1|super-secret-token');

    $raw = DB::table('email_accounts')->where('id', $account->id)->value('external_api_token');

    expect($raw)->not->toContain('super-secret-token');
});

it('wires up the email relationships', function () {
    $account = EmailAccount::factory()->create();
    $folder = EmailFolder::factory()->create(['email_account_id' => $account->id]);
    $thread = EmailThread::factory()->create([
        'email_account_id' => $account->id,
        'project_id' => $account->project_id,
    ]);
    $message = EmailMessage::factory()->create([
        'email_account_id' => $account->id,
        'email_folder_id' => $folder->id,
        'email_thread_id' => $thread->id,
    ]);

    expect($account->folders)->toHaveCount(1);
    expect($account->threads)->toHaveCount(1);
    expect($message->thread->is($thread))->toBeTrue();
    expect($message->folder->is($folder))->toBeTrue();
    expect($thread->messages->first()->is($message))->toBeTrue();
});

it('hides threads of other clients from a client user', function () {
    $ownClient = Client::factory()->create();
    $otherClient = Client::factory()->create();
    $clientUser = User::factory()->create(['role' => UserRole::Client, 'client_id' => $ownClient->id]);

    $ownProject = Project::factory()->create(['client_id' => $ownClient->id]);
    $otherProject = Project::factory()->create(['client_id' => $otherClient->id]);

    $ownThread = EmailThread::factory()->create(['project_id' => $ownProject->id]);
    EmailThread::factory()->create(['project_id' => $otherProject->id]);

    $visible = EmailThread::query()->visibleTo($clientUser)->get();

    expect($visible)->toHaveCount(1);
    expect($visible->first()->is($ownThread))->toBeTrue();
});

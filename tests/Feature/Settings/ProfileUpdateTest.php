<?php

use App\Livewire\Settings\Profile;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

test('profile page is displayed', function () {
    $this->actingAs($user = User::factory()->create());

    $this->get('/settings/profile')->assertOk();
});

test('profile information can be updated', function () {
    $user = User::factory()->create();

    $this->actingAs($user);

    $response = Livewire::test(Profile::class)
        ->set('name', 'Test User')
        ->set('email', 'test@example.com')
        ->call('updateProfileInformation');

    $response->assertHasNoErrors();

    $user->refresh();

    expect($user->name)->toEqual('Test User');
    expect($user->email)->toEqual('test@example.com');
    expect($user->email_verified_at)->toBeNull();
});

test('email verification status is unchanged when email address is unchanged', function () {
    $user = User::factory()->create();

    $this->actingAs($user);

    $response = Livewire::test(Profile::class)
        ->set('name', 'Test User')
        ->set('email', $user->email)
        ->call('updateProfileInformation');

    $response->assertHasNoErrors();

    expect($user->refresh()->email_verified_at)->not->toBeNull();
});

test('user can upload a profile picture', function () {
    Storage::fake('public');

    $user = User::factory()->create();

    $this->actingAs($user);

    Livewire::test(Profile::class)
        ->set('name', $user->name)
        ->set('email', $user->email)
        ->set('avatar', UploadedFile::fake()->image('me.jpg', 600, 600))
        ->call('updateProfileInformation')
        ->assertHasNoErrors();

    $user->refresh();

    expect($user->avatar_path)->not->toBeNull();
    expect($user->avatar_url)->toContain('/storage/');
    Storage::disk('public')->assertExists($user->avatar_path);
});

test('uploading a new picture replaces the previous one', function () {
    Storage::fake('public');

    $user = User::factory()->create(['avatar_path' => 'avatars/old.jpg']);
    Storage::disk('public')->put('avatars/old.jpg', 'old');

    $this->actingAs($user);

    Livewire::test(Profile::class)
        ->set('name', $user->name)
        ->set('email', $user->email)
        ->set('avatar', UploadedFile::fake()->image('new.jpg', 600, 600))
        ->call('updateProfileInformation')
        ->assertHasNoErrors();

    Storage::disk('public')->assertMissing('avatars/old.jpg');
    Storage::disk('public')->assertExists($user->refresh()->avatar_path);
});

test('avatar must be an image', function () {
    Storage::fake('public');

    $user = User::factory()->create();

    $this->actingAs($user);

    Livewire::test(Profile::class)
        ->set('name', $user->name)
        ->set('email', $user->email)
        ->set('avatar', UploadedFile::fake()->create('resume.pdf', 100, 'application/pdf'))
        ->call('updateProfileInformation')
        ->assertHasErrors(['avatar']);
});

test('user can remove their profile picture', function () {
    Storage::fake('public');

    $user = User::factory()->create(['avatar_path' => 'avatars/me.jpg']);
    Storage::disk('public')->put('avatars/me.jpg', 'data');

    $this->actingAs($user);

    Livewire::test(Profile::class)
        ->call('removeAvatar')
        ->assertHasNoErrors();

    expect($user->refresh()->avatar_path)->toBeNull();
    Storage::disk('public')->assertMissing('avatars/me.jpg');
});

test('user can delete their account', function () {
    $user = User::factory()->create();

    $this->actingAs($user);

    $response = Livewire::test('settings.delete-user-form')
        ->set('password', 'password')
        ->call('deleteUser');

    $response
        ->assertHasNoErrors()
        ->assertRedirect('/');

    expect($user->fresh())->toBeNull();
    expect(auth()->check())->toBeFalse();
});

test('correct password must be provided to delete account', function () {
    $user = User::factory()->create();

    $this->actingAs($user);

    $response = Livewire::test('settings.delete-user-form')
        ->set('password', 'wrong-password')
        ->call('deleteUser');

    $response->assertHasErrors(['password']);

    expect($user->fresh())->not->toBeNull();
});

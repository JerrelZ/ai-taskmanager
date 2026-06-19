<?php

use App\Livewire\System\Health;
use App\Models\User;
use App\Support\SystemStatus;
use Illuminate\Support\Facades\Cache;
use Livewire\Livewire;

test('the owner account can view the system status page', function () {
    $this->actingAs(User::factory()->admin()->create(['email' => Health::ALLOWED_EMAIL]));

    $this->get(route('system.health'))
        ->assertOk()
        ->assertSee('Systeemstatus');
});

test('another admin cannot view the system status page', function () {
    $this->actingAs(User::factory()->admin()->create(['email' => 'iemand-anders@voorbeeld.nl']));

    Livewire::test(Health::class)->assertForbidden();
});

test('the database and cache checks report ok', function () {
    $checks = collect((new SystemStatus)->checks())->keyBy('key');

    expect($checks['database']['status'])->toBe(SystemStatus::OK)
        ->and($checks['cache']['status'])->toBe(SystemStatus::OK);
});

test('the scheduler check warns without a heartbeat and is ok with a fresh one', function () {
    Cache::forget(SystemStatus::SCHEDULER_HEARTBEAT_KEY);

    $without = collect((new SystemStatus)->checks())->firstWhere('key', 'scheduler');
    expect($without['status'])->toBe(SystemStatus::WARN);

    Cache::put(SystemStatus::SCHEDULER_HEARTBEAT_KEY, now()->toIso8601String());

    $withHeartbeat = collect((new SystemStatus)->checks())->firstWhere('key', 'scheduler');
    expect($withHeartbeat['status'])->toBe(SystemStatus::OK);
});

test('the scheduler check fails when the heartbeat is stale', function () {
    Cache::put(SystemStatus::SCHEDULER_HEARTBEAT_KEY, now()->subMinutes(30)->toIso8601String());

    $check = collect((new SystemStatus)->checks())->firstWhere('key', 'scheduler');

    expect($check['status'])->toBe(SystemStatus::FAIL);
});

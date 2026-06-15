<?php

use App\Models\User;

test('home redirects mobile visitors to the messages chat', function () {
    $this->actingAs(User::factory()->create())
        ->get('/', ['User-Agent' => 'Mozilla/5.0 (iPhone; CPU iPhone OS 17_0 like Mac OS X) AppleWebKit/605.1.15 Mobile/15E148 Safari/604.1'])
        ->assertRedirect('/messages');
});

test('home redirects android phone visitors to the messages chat', function () {
    $this->actingAs(User::factory()->create())
        ->get('/', ['User-Agent' => 'Mozilla/5.0 (Linux; Android 14; Pixel 8) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0 Mobile Safari/537.36'])
        ->assertRedirect('/messages');
});

test('home redirects desktop visitors to the tickets overview', function () {
    $this->actingAs(User::factory()->create())
        ->get('/', ['User-Agent' => 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0 Safari/537.36'])
        ->assertRedirect('/tickets');
});

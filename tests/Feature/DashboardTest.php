<?php

use App\Models\User;

test('the legacy dashboard route redirects to the home', function () {
    $this->get(route('dashboard'))->assertRedirect('/');
});

test('authenticated users land on the tickets workspace from home', function () {
    $this->actingAs(User::factory()->create());

    $this->get('/')->assertRedirect('/tickets');
});

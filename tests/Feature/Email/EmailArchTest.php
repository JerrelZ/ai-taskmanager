<?php

use Illuminate\Contracts\Queue\ShouldQueue;

arch('email jobs are queueable', function () {
    expect('App\Jobs\Email')
        ->toImplement(ShouldQueue::class);
});

arch('email services do not depend on Livewire', function () {
    expect('App\Services\Email')
        ->not->toUse('Livewire\Component');
});

// Customer data is reachable only through a project's read-only support API.
// Touching the query builder here would mean opening a database connection to
// an external host again — the exact leak this design removed.
arch('email services and MCP tools never query a database directly', function () {
    expect(['App\Services\Email', 'App\Mcp'])
        ->not->toUse(['Illuminate\Support\Facades\DB', 'Illuminate\Database\Connection']);
});

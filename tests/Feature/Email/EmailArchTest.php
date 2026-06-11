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

<?php

use App\Livewire\Clients\Index as ClientsIndex;
use App\Livewire\Email\Inbox as EmailInbox;
use App\Livewire\Messages\Index as MessagesIndex;
use App\Livewire\Projects\Board;
use App\Livewire\Projects\Index;
use App\Livewire\Team\Index as TeamIndex;
use App\Livewire\Tickets\Index as TicketsIndex;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth'])->group(function () {
    Route::livewire('tickets', TicketsIndex::class)->name('tickets.index');
    Route::livewire('messages', MessagesIndex::class)->name('messages.index');
    Route::livewire('projects', Index::class)->name('projects.index');
    Route::livewire('projects/{project}', Board::class)->name('projects.board');
    Route::livewire('projects/{project}/inbox', EmailInbox::class)->name('projects.inbox');

    // Admin-only management.
    Route::livewire('clients', ClientsIndex::class)->name('clients.index');
    Route::livewire('team', TeamIndex::class)->name('team.index');
});

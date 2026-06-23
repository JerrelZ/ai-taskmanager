<?php

use App\Http\Controllers\TicketController;
use App\Livewire\Clients\Index as ClientsIndex;
use App\Livewire\Email\Inbox as EmailInbox;
use App\Livewire\Messages\Index as MessagesIndex;
use App\Livewire\Notifications\Index as NotificationsIndex;
use App\Livewire\Projects\Board;
use App\Livewire\Projects\Index;
use App\Livewire\System\Health as SystemHealth;
use App\Livewire\Team\Index as TeamIndex;
use App\Livewire\Tickets\Index as TicketsIndex;
use App\Livewire\Tickets\Ready as TicketsReady;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth'])->group(function () {
    Route::livewire('tickets', TicketsIndex::class)->name('tickets.index');
    Route::livewire('tickets/ready', TicketsReady::class)->name('tickets.ready');

    // Canonical, shareable ticket URL (e.g. /tickets/WEB-12/inlog-knop-stuk).
    // The trailing slug is decorative; the identifier resolves the ticket.
    Route::get('tickets/{identifier}/{slug?}', [TicketController::class, 'show'])
        ->where('identifier', '[A-Za-z0-9]+-[0-9]+')
        ->name('tickets.show');
    Route::livewire('messages', MessagesIndex::class)->name('messages.index');
    Route::livewire('notifications', NotificationsIndex::class)->name('notifications.index');
    Route::livewire('projects', Index::class)->name('projects.index');
    Route::livewire('projects/{project}', Board::class)->name('projects.board');
    Route::livewire('projects/{project}/inbox', EmailInbox::class)->name('projects.inbox');

    // Admin-only management.
    Route::livewire('clients', ClientsIndex::class)->name('clients.index');
    Route::livewire('team', TeamIndex::class)->name('team.index');

    // Production status — gated to a single owner account inside the component.
    Route::livewire('system-status', SystemHealth::class)->name('system.health');
});

<?php

namespace App\Livewire\Notifications;

use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * Full-page notification center: the complete, paginated history of a user's
 * in-app notifications, with mark-as-read handling. The topbar bell shows only
 * the most recent few and links here for the rest.
 */
#[Title('Meldingen')]
class Index extends Component
{
    use WithPagination;

    #[Computed]
    public function unreadCount(): int
    {
        return Auth::user()->unreadNotifications()->count();
    }

    public function markAsRead(string $id): void
    {
        Auth::user()->unreadNotifications()->where('id', $id)->update(['read_at' => now()]);

        unset($this->unreadCount);
    }

    public function markAllAsRead(): void
    {
        Auth::user()->unreadNotifications->markAsRead();

        unset($this->unreadCount);
    }

    public function render(): View
    {
        return view('livewire.notifications.index', [
            'notifications' => Auth::user()->notifications()->latest()->paginate(20),
        ]);
    }
}

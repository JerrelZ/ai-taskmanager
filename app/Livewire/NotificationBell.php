<?php

namespace App\Livewire;

use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Computed;
use Livewire\Component;

/**
 * Topbar notification center: shows the unread count and a dropdown of recent
 * in-app notifications, with mark-as-read handling.
 */
class NotificationBell extends Component
{
    #[Computed]
    public function unreadCount(): int
    {
        return Auth::user()->unreadNotifications()->count();
    }

    /**
     * @return Collection<int, DatabaseNotification>
     */
    #[Computed]
    public function notifications(): Collection
    {
        return Auth::user()->notifications()->latest()->limit(12)->get();
    }

    public function markAsRead(string $id): void
    {
        Auth::user()->unreadNotifications()->where('id', $id)->update(['read_at' => now()]);

        unset($this->unreadCount, $this->notifications);
    }

    public function markAllAsRead(): void
    {
        Auth::user()->unreadNotifications->markAsRead();

        unset($this->unreadCount, $this->notifications);
    }

    public function render()
    {
        return view('livewire.notification-bell');
    }
}

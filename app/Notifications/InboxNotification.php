<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;

/**
 * A lightweight in-app (database) notification for inbox events such as a thread
 * being assigned or a new reply arriving on a thread you handle.
 */
class InboxNotification extends Notification
{
    use Queueable;

    public function __construct(
        public readonly string $title,
        public readonly string $body,
        public readonly string $url,
        public readonly string $icon = 'bell',
    ) {}

    /**
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return ['database'];
    }

    /**
     * @return array<string, string>
     */
    public function toArray(object $notifiable): array
    {
        return [
            'title' => $this->title,
            'body' => $this->body,
            'url' => $this->url,
            'icon' => $this->icon,
        ];
    }
}

<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;
use NotificationChannels\WebPush\WebPushChannel;
use NotificationChannels\WebPush\WebPushMessage;

/**
 * Periodic digest summarising how many unread messenger messages a user has
 * accumulated since their last digest, delivered in-app and as a web-push.
 */
class MessageDigestNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public readonly int $count,
        public readonly string $url,
    ) {}

    /**
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return ['database', WebPushChannel::class];
    }

    /**
     * @return array<string, string>
     */
    public function toArray(object $notifiable): array
    {
        return [
            'title' => __('Nieuwe berichten'),
            'body' => $this->summary(),
            'url' => $this->url,
            'icon' => 'chat-bubble-left-right',
        ];
    }

    public function toWebPush(object $notifiable, Notification $notification): WebPushMessage
    {
        return (new WebPushMessage)
            ->title(__('Nieuwe berichten'))
            ->icon('/icon-192.png')
            ->badge('/icon-192.png')
            ->body($this->summary())
            ->tag('message-digest')
            ->data(['url' => $this->url]);
    }

    private function summary(): string
    {
        return trans_choice(
            '{1}Je hebt :count nieuw bericht.|[2,*]Je hebt :count nieuwe berichten.',
            $this->count,
            ['count' => $this->count],
        );
    }
}

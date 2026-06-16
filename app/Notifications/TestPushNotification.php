<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;
use NotificationChannels\WebPush\WebPushChannel;
use NotificationChannels\WebPush\WebPushMessage;

/**
 * A self-sent notification used by the settings page so a user can verify that
 * push delivery to their current device actually works.
 */
class TestPushNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(public readonly string $url) {}

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
            'title' => __('Testmelding'),
            'body' => __('Als je dit ziet, werken je notificaties.'),
            'url' => $this->url,
            'icon' => 'bell',
        ];
    }

    public function toWebPush(object $notifiable, Notification $notification): WebPushMessage
    {
        return (new WebPushMessage)
            ->title(__('Testmelding'))
            ->icon('/icon-192.png')
            ->badge('/icon-192.png')
            ->body(__('Als je dit ziet, werken je notificaties.'))
            ->tag('test-notification')
            ->data(['url' => $this->url]);
    }
}

<?php

namespace App\Notifications;

use App\Models\Message;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Str;
use NotificationChannels\WebPush\WebPushChannel;
use NotificationChannels\WebPush\WebPushMessage;

/**
 * Realtime notification sent to a conversation participant for each new message,
 * delivered both in-app (database) and as a browser web-push.
 */
class NewMessageNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public readonly Message $message,
        public readonly string $title,
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
            'title' => $this->title,
            'body' => $this->preview(),
            'url' => $this->url,
            'icon' => 'chat-bubble-left-right',
        ];
    }

    public function toWebPush(object $notifiable, Notification $notification): WebPushMessage
    {
        return (new WebPushMessage)
            ->title($this->title)
            ->icon('/icon-192.png')
            ->badge('/icon-192.png')
            ->body($this->preview())
            ->tag('conversation-'.$this->message->conversation_id)
            ->data(['url' => $this->url]);
    }

    private function preview(): string
    {
        $body = trim($this->message->body);

        if ($body === '') {
            return __('Stuurde een bijlage.');
        }

        return Str::limit($body, 120);
    }
}

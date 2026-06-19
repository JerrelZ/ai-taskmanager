<?php

namespace App\Notifications;

use App\Models\Activity;
use App\Models\Message;
use App\Models\Task;
use App\Models\User;
use App\Support\DailyRecap;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Collection;

/**
 * The once-a-day e-mail summarising a user's open tasks, looming deadlines and
 * unread chat. Only dispatched when {@see DailyRecap} reports
 * activity, so it never lands as an empty message.
 */
class DailyRecapNotification extends Notification implements ShouldQueue
{
    use Queueable;

    /**
     * @param  array{
     *     assignedTasks: Collection<int, Task>,
     *     deadlines: Collection<int, Task>,
     *     recentActivity: Collection<int, Activity>,
     *     unreadMessages: int,
     *     unreadMessagePreviews: Collection<int, Message>,
     *     hasActivity: bool,
     * }  $recap
     */
    public function __construct(public readonly array $recap) {}

    /**
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        /** @var User $notifiable */
        return (new MailMessage)
            ->subject(__('Je dagelijkse overzicht'))
            ->markdown('mail.recap', [
                'user' => $notifiable,
                'assignedTasks' => $this->recap['assignedTasks'],
                'deadlines' => $this->recap['deadlines'],
                'recentActivity' => $this->recap['recentActivity'],
                'unreadMessages' => $this->recap['unreadMessages'],
                'unreadMessagePreviews' => $this->recap['unreadMessagePreviews'],
                'ticketsUrl' => route('tickets.index'),
                'messagesUrl' => route('messages.index'),
            ]);
    }
}

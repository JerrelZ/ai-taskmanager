<?php

namespace App\Livewire\Settings;

use App\Enums\MessengerNotificationMode;
use App\Notifications\TestPushNotification;
use Flux\Flux;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Title;
use Livewire\Attributes\Validate;
use Livewire\Component;

#[Title('Notification settings')]
class Notifications extends Component
{
    public bool $enabled = true;

    public string $mode = 'realtime';

    #[Validate('required|integer|min:1|max:24')]
    public int $digestIntervalHours = 4;

    public function mount(): void
    {
        $user = Auth::user();

        $this->enabled = (bool) ($user->messenger_notifications_enabled ?? true);
        $this->mode = ($user->messenger_notification_mode ?? MessengerNotificationMode::Realtime)->value;
        $this->digestIntervalHours = $user->messenger_digest_interval_hours ?? 4;
    }

    public function save(): void
    {
        $validated = $this->validate([
            'enabled' => ['boolean'],
            'mode' => ['required', Rule::enum(MessengerNotificationMode::class)],
            'digestIntervalHours' => ['required', 'integer', 'min:1', 'max:24'],
        ]);

        $user = Auth::user();
        $user->messenger_notifications_enabled = $validated['enabled'];
        $user->messenger_notification_mode = MessengerNotificationMode::from($validated['mode']);
        $user->messenger_digest_interval_hours = $validated['digestIntervalHours'];
        $user->save();

        Flux::toast(variant: 'success', text: __('Notification settings updated.'));
    }

    /**
     * Send a test notification to the current user so they can confirm push
     * delivery works on this device.
     */
    public function sendTestNotification(): void
    {
        Auth::user()->notify(new TestPushNotification(route('messages.index')));

        Flux::toast(text: __('Testmelding verstuurd.'));
    }
}

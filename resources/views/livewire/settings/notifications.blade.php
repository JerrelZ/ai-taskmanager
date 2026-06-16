<section class="w-full">
    @include('partials.settings-heading')

    <flux:heading class="sr-only">{{ __('Notification settings') }}</flux:heading>

    <x-settings.layout :heading="__('Notifications')" :subheading="__('Choose how you want to hear about new messages')">
        <form wire:submit="save" class="my-6 w-full space-y-6">
            <flux:switch
                wire:model.live="enabled"
                :label="__('Messenger notifications')"
                :description="__('Receive a notification when you get a new message.')"
            />

            <div @class(['space-y-6', 'opacity-50 pointer-events-none' => ! $enabled])>
                <flux:radio.group wire:model.live="mode" :label="__('Delivery')" variant="cards" class="flex-col">
                    <flux:radio
                        value="realtime"
                        :label="__('Realtime')"
                        :description="__('Get notified immediately for every new message.')"
                    />
                    <flux:radio
                        value="digest"
                        :label="__('Periodic summary')"
                        :description="__('Get one notification every few hours with the number of new messages.')"
                    />
                </flux:radio.group>

                @if ($mode === 'digest')
                    <flux:input
                        type="number"
                        min="1"
                        max="24"
                        wire:model="digestIntervalHours"
                        :label="__('Send a summary every (hours)')"
                    />
                @endif
            </div>

            <div class="flex items-center gap-4">
                <flux:button variant="primary" type="submit">{{ __('Save') }}</flux:button>
            </div>
        </form>

        <flux:separator class="my-8" variant="subtle" />

        {{-- Per-device web-push opt-in. Realtime/digest notifications are only
             pushed to your phone or desktop once this device is enabled. --}}
        <div
            x-data="pushToggle({
                publicKey: @js(config('webpush.vapid.public_key')),
                storeUrl: @js(route('push-subscriptions.store')),
                destroyUrl: @js(route('push-subscriptions.destroy')),
                csrf: document.querySelector('meta[name=\'csrf-token\']').content,
            })"
            class="space-y-3"
        >
            <div>
                <flux:heading size="sm">{{ __('Push on this device') }}</flux:heading>
                <flux:subheading>{{ __('Allow notifications to be pushed to this device, even when the app is closed.') }}</flux:subheading>
            </div>

            <template x-if="!supported">
                <flux:callout variant="subtle" icon="exclamation-triangle">
                    {{ __('This browser does not support push notifications.') }}
                </flux:callout>
            </template>

            <template x-if="supported && denied">
                <flux:callout variant="warning" icon="bell-slash">
                    {{ __('Notifications are blocked in your browser settings. Allow them to enable push.') }}
                </flux:callout>
            </template>

            <div x-show="supported" class="flex flex-wrap items-center gap-3">
                <flux:button x-show="!subscribed" x-on:click="enable()" x-bind:disabled="busy || denied" icon="bell">
                    {{ __('Enable on this device') }}
                </flux:button>
                <flux:button x-show="subscribed" x-on:click="disable()" x-bind:disabled="busy" variant="ghost" icon="bell-slash">
                    {{ __('Disable on this device') }}
                </flux:button>
                <flux:badge x-show="subscribed" color="green" size="sm">{{ __('Active') }}</flux:badge>
                <flux:button x-show="subscribed" wire:click="sendTestNotification" variant="ghost" size="sm" icon="paper-airplane">
                    {{ __('Send test') }}
                </flux:button>
            </div>
        </div>
    </x-settings.layout>
</section>

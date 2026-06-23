<section class="w-full">
    @include('partials.settings-heading')

    <flux:heading class="sr-only">{{ __('Profile settings') }}</flux:heading>

    <x-settings.layout :heading="__('Profile')" :subheading="__('Update your name and email address')">
        <form wire:submit="updateProfileInformation" class="my-6 w-full space-y-6">
            <flux:field>
                <flux:label>{{ __('Profile picture') }}</flux:label>

                <div class="flex items-center gap-4">
                    @if ($avatar && $avatar->isPreviewable())
                        <flux:avatar size="lg" circle :src="$avatar->temporaryUrl()" />
                    @else
                        <flux:avatar
                            size="lg"
                            circle
                            :name="auth()->user()->name"
                            :initials="auth()->user()->initials()"
                            :src="auth()->user()->avatar_url"
                        />
                    @endif

                    <div class="flex flex-col items-start gap-2">
                        <flux:input type="file" wire:model="avatar" accept="image/*" class="max-w-xs" />

                        @if (auth()->user()->avatar_path)
                            <flux:button variant="ghost" size="sm" wire:click="removeAvatar" type="button">
                                {{ __('Remove photo') }}
                            </flux:button>
                        @endif
                    </div>
                </div>

                <flux:error name="avatar" />
            </flux:field>

            <flux:input wire:model="name" :label="__('Name')" type="text" required autofocus autocomplete="name" />

            <div>
                <flux:input wire:model="email" :label="__('Email')" type="email" required autocomplete="email" />

                @if ($this->hasUnverifiedEmail)
                    <div>
                        <flux:text class="mt-4">
                            {{ __('Your email address is unverified.') }}

                            <flux:link class="text-sm cursor-pointer" wire:click.prevent="resendVerificationNotification">
                                {{ __('Click here to re-send the verification email.') }}
                            </flux:link>
                        </flux:text>

                    </div>
                @endif
            </div>

            <div class="flex items-center gap-4">
                <flux:button variant="primary" type="submit">{{ __('Save') }}</flux:button>
            </div>
        </form>

        @if ($this->showDeleteUser)
            <livewire:settings.delete-user-form />
        @endif
    </x-settings.layout>
</section>

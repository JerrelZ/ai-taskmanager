<x-layouts::auth :title="__('Uitnodiging accepteren')">
    <div class="flex flex-col gap-6">
        <x-auth-header
            :title="__('Welkom bij :workspace', ['workspace' => $invitation->workspace->name])"
            :description="__('Maak je account aan om aan de slag te gaan.')"
        />

        <x-auth-session-status class="text-center" :status="session('status')" />

        <form method="POST" action="{{ route('invitations.store', $token) }}" class="flex flex-col gap-6">
            @csrf

            <flux:input
                :label="__('E-mailadres')"
                type="email"
                :value="$invitation->email"
                readonly
                disabled
            />

            <flux:input
                name="name"
                :label="__('Naam')"
                :value="old('name')"
                type="text"
                required
                autofocus
                autocomplete="name"
                :placeholder="__('Volledige naam')"
            />

            <flux:input
                name="password"
                :label="__('Wachtwoord')"
                type="password"
                required
                autocomplete="new-password"
                :placeholder="__('Wachtwoord')"
                passwordrules="{{ \Illuminate\Validation\Rules\Password::defaults()->toPasswordRulesString() }}"
                viewable
            />

            <flux:input
                name="password_confirmation"
                :label="__('Bevestig wachtwoord')"
                type="password"
                required
                autocomplete="new-password"
                :placeholder="__('Bevestig wachtwoord')"
                viewable
            />

            <flux:button type="submit" variant="primary" class="w-full">
                {{ __('Account aanmaken') }}
            </flux:button>
        </form>
    </div>
</x-layouts::auth>

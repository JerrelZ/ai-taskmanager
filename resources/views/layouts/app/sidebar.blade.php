<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        @include('partials.head')
    </head>
    <body class="min-h-screen bg-zinc-50 font-sans text-zinc-800 antialiased dark:bg-zinc-950 dark:text-zinc-200">
        <flux:sidebar sticky collapsible="mobile" class="border-e border-zinc-200 bg-zinc-100/50 max-lg:bg-zinc-50 dark:border-zinc-800 dark:bg-zinc-900/40 max-lg:dark:bg-zinc-900">
            <flux:sidebar.header>
                <a href="{{ route('tickets.index') }}" wire:navigate class="flex items-center gap-2 px-1 py-1">
                    <span class="flex size-7 items-center justify-center rounded-full bg-brand-600 font-display text-lg leading-none text-white">t</span>
                    <span class="font-display text-2xl leading-none text-zinc-900 dark:text-zinc-50">tasks</span>
                </a>
                <flux:spacer />
                <livewire:notification-bell />
                <flux:sidebar.collapse class="lg:hidden" />
            </flux:sidebar.header>

            <flux:sidebar.nav>
                <flux:sidebar.item icon="queue-list" :href="route('tickets.index')" :current="request()->routeIs('tickets.index') && ! request()->boolean('onlyStale')" wire:navigate>
                    {{ __('Alle tickets') }}
                </flux:sidebar.item>
                @php $unreadMessages = auth()->user()->unreadMessagesCount(); @endphp
                <flux:sidebar.item icon="chat-bubble-left-right" :href="route('messages.index')" :current="request()->routeIs('messages.*')" :badge="$unreadMessages > 0 ? $unreadMessages : null" badge:color="brand" wire:navigate>
                    {{ __('Berichten') }}
                </flux:sidebar.item>
                <flux:sidebar.item icon="clock" :href="route('tickets.index', ['onlyStale' => 1])" :current="request()->routeIs('tickets.index') && request()->boolean('onlyStale')" wire:navigate>
                    {{ __('Verouderd') }}
                </flux:sidebar.item>
                <flux:sidebar.item icon="sparkles" :href="route('tickets.ready')" :current="request()->routeIs('tickets.ready')" wire:navigate>
                    {{ __('Klaar voor Claude Code') }}
                </flux:sidebar.item>
                <flux:sidebar.item icon="rectangle-stack" :href="route('projects.index')" :current="request()->routeIs('projects.index')" wire:navigate>
                    {{ __('Projecten') }}
                </flux:sidebar.item>
            </flux:sidebar.nav>

            @php $sidebarProjects = \App\Models\Project::query()->visibleTo(auth()->user())->active()->orderBy('position')->orderBy('name')->get(); @endphp
            @if ($sidebarProjects->isNotEmpty())
                <flux:sidebar.nav>
                    <flux:sidebar.group :heading="__('Projecten')" expandable :expanded="true" class="grid">
                        @foreach ($sidebarProjects as $sidebarProject)
                            <flux:sidebar.item :href="route('projects.board', $sidebarProject)" :current="request()->routeIs('projects.board') && request()->route('project')?->is($sidebarProject)" wire:navigate>
                                <span class="flex items-center gap-2">
                                    <span class="size-2 shrink-0 rounded-full bg-{{ $sidebarProject->color }}-500"></span>
                                    <span class="truncate">{{ $sidebarProject->name }}</span>
                                </span>
                            </flux:sidebar.item>
                        @endforeach
                    </flux:sidebar.group>
                </flux:sidebar.nav>
            @endif

            @if (auth()->user()->isAdmin())
                <flux:sidebar.nav>
                    <flux:sidebar.group :heading="__('Beheer')" class="grid">
                        <flux:sidebar.item icon="building-office-2" :href="route('clients.index')" :current="request()->routeIs('clients.*')" wire:navigate>
                            {{ __('Klanten') }}
                        </flux:sidebar.item>
                        <flux:sidebar.item icon="users" :href="route('team.index')" :current="request()->routeIs('team.*')" wire:navigate>
                            {{ __('Team') }}
                        </flux:sidebar.item>
                    </flux:sidebar.group>
                </flux:sidebar.nav>
            @endif

            <flux:spacer />

            <div class="px-2 pb-2">
                <flux:radio.group x-data variant="segmented" size="sm" x-model="$flux.appearance" class="w-full">
                    <flux:radio value="light" icon="sun" />
                    <flux:radio value="dark" icon="moon" />
                    <flux:radio value="system" icon="computer-desktop" />
                </flux:radio.group>
            </div>

            <x-desktop-user-menu class="hidden lg:block" :name="auth()->user()->name" />
        </flux:sidebar>

        <!-- Mobile User Menu -->
        <flux:header class="lg:hidden">
            <flux:sidebar.toggle class="lg:hidden" icon="bars-2" inset="left" />

            <flux:spacer />

            <livewire:notification-bell />

            <flux:dropdown position="top" align="end">
                <flux:profile
                    :initials="auth()->user()->initials()"
                    icon-trailing="chevron-down"
                />

                <flux:menu>
                    <flux:menu.radio.group>
                        <div class="p-0 text-sm font-normal">
                            <div class="flex items-center gap-2 px-1 py-1.5 text-start text-sm">
                                <flux:avatar
                                    :name="auth()->user()->name"
                                    :initials="auth()->user()->initials()"
                                />

                                <div class="grid flex-1 text-start text-sm leading-tight">
                                    <flux:heading class="truncate">{{ auth()->user()->name }}</flux:heading>
                                    <flux:text class="truncate">{{ auth()->user()->email }}</flux:text>
                                </div>
                            </div>
                        </div>
                    </flux:menu.radio.group>

                    <flux:menu.separator />

                    <flux:menu.radio.group>
                        <flux:menu.item :href="route('profile.edit')" icon="cog" wire:navigate>
                            {{ __('Settings') }}
                        </flux:menu.item>
                    </flux:menu.radio.group>

                    <flux:menu.separator />

                    <form method="POST" action="{{ route('logout') }}" class="w-full">
                        @csrf
                        <flux:menu.item
                            as="button"
                            type="submit"
                            icon="arrow-right-start-on-rectangle"
                            class="w-full cursor-pointer"
                            data-test="logout-button"
                        >
                            {{ __('Log out') }}
                        </flux:menu.item>
                    </form>
                </flux:menu>
            </flux:dropdown>
        </flux:header>

        {{ $slot }}

        @persist('toast')
            <flux:toast.group>
                <flux:toast />
            </flux:toast.group>
        @endpersist

        <script>
            document.addEventListener('livewire:init', () => {
                Livewire.on('copy-to-clipboard', (event) => {
                    const text = Array.isArray(event) ? event[0]?.text : event?.text;
                    if (text && navigator.clipboard) {
                        navigator.clipboard.writeText(text);
                    }
                });
            });
        </script>

        @fluxScripts
    </body>
</html>

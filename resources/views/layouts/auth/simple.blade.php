<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        @include('partials.head')
    </head>
    <body class="min-h-screen bg-zinc-50 font-sans text-zinc-800 antialiased dark:bg-zinc-950 dark:text-zinc-200">
        <div class="relative flex min-h-svh flex-col items-center justify-center overflow-hidden p-6 md:p-10">
            {{-- Soft brand glow behind the card --}}
            <div aria-hidden="true" class="pointer-events-none absolute inset-x-0 -top-40 -z-10 flex justify-center">
                <div class="size-[36rem] rounded-full bg-brand-500/20 blur-[120px] dark:bg-brand-500/15"></div>
            </div>

            <div class="flex w-full max-w-sm flex-col gap-8">
                {{-- Brand --}}
                <a href="{{ route('home') }}" wire:navigate class="flex flex-col items-center gap-3">
                    <span class="flex size-14 items-center justify-center rounded-2xl bg-linear-to-br from-brand-500 to-brand-700 text-white shadow-lg shadow-brand-500/30">
                        <x-app-logo-icon class="size-8" />
                    </span>
                    <span class="font-display text-3xl lowercase leading-none text-zinc-900 dark:text-zinc-50">{{ config('app.name', 'Tasks') }}</span>
                </a>

                {{-- Card --}}
                <div class="rounded-2xl border border-zinc-200 bg-white p-8 shadow-sm dark:border-zinc-800 dark:bg-zinc-900">
                    {{ $slot }}
                </div>
            </div>
        </div>

        @persist('toast')
            <flux:toast.group>
                <flux:toast />
            </flux:toast.group>
        @endpersist

        @fluxScripts
    </body>
</html>

@props([
    'sidebar' => false,
])

@if ($sidebar)
    <flux:sidebar.brand :name="config('app.name', 'Tasks')" {{ $attributes }}>
        <x-slot name="logo" class="flex aspect-square size-8 items-center justify-center rounded-lg bg-linear-to-br from-brand-500 to-brand-700 text-white shadow-sm">
            <x-app-logo-icon class="size-5" />
        </x-slot>
    </flux:sidebar.brand>
@else
    <flux:brand :name="config('app.name', 'Tasks')" {{ $attributes }}>
        <x-slot name="logo" class="flex aspect-square size-8 items-center justify-center rounded-lg bg-linear-to-br from-brand-500 to-brand-700 text-white shadow-sm">
            <x-app-logo-icon class="size-5" />
        </x-slot>
    </flux:brand>
@endif

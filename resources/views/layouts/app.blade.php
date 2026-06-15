<x-layouts::app.sidebar :title="$title ?? null">
    <flux:main @class(['max-lg:pb-20' => ! request()->routeIs('messages.*')])>
        {{ $slot }}
    </flux:main>
</x-layouts::app.sidebar>

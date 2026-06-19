@php
    use App\Support\SystemStatus;

    $checks = $this->checks;
    $hasFail = collect($checks)->contains('status', SystemStatus::FAIL);
    $hasWarn = collect($checks)->contains('status', SystemStatus::WARN);
    $overall = $hasFail ? SystemStatus::FAIL : ($hasWarn ? SystemStatus::WARN : SystemStatus::OK);

    $meta = [
        SystemStatus::OK => ['color' => 'green', 'icon' => 'check-circle', 'label' => __('In orde')],
        SystemStatus::WARN => ['color' => 'amber', 'icon' => 'exclamation-triangle', 'label' => __('Aandacht')],
        SystemStatus::FAIL => ['color' => 'red', 'icon' => 'x-circle', 'label' => __('Probleem')],
    ];
@endphp

<div class="flex h-full w-full flex-1 flex-col gap-6 overflow-y-auto p-4 lg:p-6">
    <div class="flex items-start justify-between gap-4">
        <div>
            <h1 class="font-display text-3xl leading-none text-zinc-900 lg:text-4xl dark:text-zinc-50">{{ __('Systeemstatus') }}</h1>
            <flux:subheading class="mt-1.5">{{ __('Live productiechecklist — draait alles wat moet draaien?') }}</flux:subheading>
        </div>
        <flux:button wire:click="refresh" size="sm" variant="subtle" icon="arrow-path">
            <span class="max-sm:hidden">{{ __('Vernieuwen') }}</span>
        </flux:button>
    </div>

    {{-- Overall banner --}}
    <div @class([
        'flex items-center gap-3 rounded-xl border px-4 py-3',
        'border-green-200 bg-green-50 dark:border-green-900/50 dark:bg-green-950/30' => $overall === SystemStatus::OK,
        'border-amber-200 bg-amber-50 dark:border-amber-900/50 dark:bg-amber-950/30' => $overall === SystemStatus::WARN,
        'border-red-200 bg-red-50 dark:border-red-900/50 dark:bg-red-950/30' => $overall === SystemStatus::FAIL,
    ])>
        <flux:icon :name="$meta[$overall]['icon']" class="size-6 text-{{ $meta[$overall]['color'] }}-500" />
        <div>
            <div class="font-medium text-zinc-900 dark:text-zinc-100">
                @switch($overall)
                    @case(SystemStatus::OK) {{ __('Alles draait naar behoren.') }} @break
                    @case(SystemStatus::WARN) {{ __('Er is iets dat aandacht vraagt.') }} @break
                    @default {{ __('Er is een probleem dat actie vereist.') }}
                @endswitch
            </div>
            <div class="text-sm text-zinc-500">{{ __('Laatst gecontroleerd zojuist.') }}</div>
        </div>
    </div>

    {{-- Individual checks --}}
    <div class="grid gap-3 sm:grid-cols-2">
        @foreach ($checks as $check)
            <div wire:key="check-{{ $check['key'] }}" class="flex items-start gap-3 rounded-xl border border-zinc-200 bg-white p-4 dark:border-zinc-700 dark:bg-zinc-900">
                <flux:icon :name="$meta[$check['status']]['icon']" class="mt-0.5 size-5 shrink-0 text-{{ $meta[$check['status']]['color'] }}-500" />
                <div class="min-w-0">
                    <div class="flex items-center gap-2">
                        <span class="font-medium text-zinc-900 dark:text-zinc-100">{{ $check['label'] }}</span>
                        <flux:badge size="sm" :color="$meta[$check['status']]['color']">{{ $meta[$check['status']]['label'] }}</flux:badge>
                    </div>
                    <p class="mt-0.5 text-sm text-zinc-500">{{ $check['message'] }}</p>
                </div>
            </div>
        @endforeach
    </div>
</div>

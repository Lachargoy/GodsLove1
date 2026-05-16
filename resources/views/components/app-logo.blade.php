@props([
    'sidebar' => false,
])

@if($sidebar)
    <flux:sidebar.brand :name="config('app.name', 'GodsLove')" {{ $attributes }}>
        <x-slot name="logo" class="godslove-mark size-8 text-xs">
            <span>GL</span>
        </x-slot>
    </flux:sidebar.brand>
@else
    <flux:brand :name="config('app.name', 'GodsLove')" {{ $attributes }}>
        <x-slot name="logo" class="godslove-mark size-8 text-xs">
            <span>GL</span>
        </x-slot>
    </flux:brand>
@endif

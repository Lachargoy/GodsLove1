<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="dark">
    <head>
        @include('partials.head')
    </head>
    <body class="min-h-screen bg-white antialiased">
        <div class="relative grid h-dvh flex-col items-center justify-center px-8 sm:px-0 lg:max-w-none lg:grid-cols-2 lg:px-0">
            <div class="relative hidden h-full flex-col overflow-hidden bg-slate-950 p-10 text-white lg:flex">
                <div class="absolute inset-0 opacity-30" style="background-image: linear-gradient(to right, rgba(255,255,255,.10) 1px, transparent 1px), linear-gradient(to bottom, rgba(255,255,255,.08) 1px, transparent 1px); background-size: 28px 28px;"></div>
                <a href="{{ route('home') }}" class="relative z-20 flex items-center text-lg font-medium" wire:navigate>
                    <span class="godslove-mark mr-3 size-11 text-base"><span>GL</span></span>
                    {{ config('app.name', 'GodsLove') }}
                </a>

                <div class="relative z-20 mt-auto">
                    <blockquote class="space-y-2">
                        <flux:heading size="lg">Mas frescas, mas ricas, mas para ti.</flux:heading>
                        <footer><flux:heading>GodsLove operaciones</flux:heading></footer>
                    </blockquote>
                </div>
            </div>
            <div class="w-full lg:p-8">
                <div class="mx-auto flex w-full flex-col justify-center space-y-6 sm:w-[350px]">
                    <a href="{{ route('home') }}" class="z-20 flex flex-col items-center gap-2 font-medium lg:hidden" wire:navigate>
                        <span class="godslove-mark size-12 text-base"><span>GL</span></span>
                        <span class="text-sm font-black uppercase tracking-[0.18em] text-rose-500">{{ config('app.name', 'GodsLove') }}</span>
                    </a>
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

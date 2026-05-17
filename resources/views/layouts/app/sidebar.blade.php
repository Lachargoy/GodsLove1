<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        @include('partials.head')
    </head>
    <body class="min-h-screen bg-transparent text-slate-900 antialiased">
        @php
            $navigationGroups = [
                [
                    'label' => 'Hoy',
                    'items' => [
                        ['label' => 'Dashboard', 'description' => 'Vista general', 'route' => 'dashboard', 'active' => 'dashboard', 'icon' => 'home', 'accent' => 'bg-sky-500'],
                        ['label' => 'Asistente', 'description' => 'IA operativa', 'route' => 'asistente.index', 'active' => 'asistente.*', 'icon' => 'sparkles', 'accent' => 'bg-fuchsia-500'],
                        ['label' => 'Ventas', 'description' => 'Punto de venta', 'route' => 'ventas.punto', 'active' => 'ventas.*', 'icon' => 'shopping-cart', 'accent' => 'bg-orange-500'],
                        ['label' => 'Caja', 'description' => 'Corte diario', 'route' => 'caja.corte', 'active' => 'caja.*', 'icon' => 'banknotes', 'accent' => 'bg-emerald-500'],
                    ],
                ],
                [
                    'label' => 'Gestion',
                    'items' => [
                        ['label' => 'Productos', 'description' => 'Catalogo y recetas', 'route' => 'productos.index', 'active' => 'productos.*', 'icon' => 'shopping-bag', 'accent' => 'bg-rose-500'],
                        ['label' => 'Insumos', 'description' => 'Stock y costos', 'route' => 'insumos.index', 'active' => 'insumos.*', 'icon' => 'cube', 'accent' => 'bg-indigo-500'],
                        ['label' => 'Categorias', 'description' => 'Orden del sistema', 'route' => 'categorias.index', 'active' => 'categorias.*', 'icon' => 'tag', 'accent' => 'bg-violet-500'],
                    ],
                ],
                [
                    'label' => 'Control',
                    'items' => [
                        ['label' => 'Movimientos', 'description' => 'Entradas y salidas', 'route' => 'inventario.movimientos', 'active' => 'inventario.*', 'icon' => 'arrows-right-left', 'accent' => 'bg-slate-700'],
                        ['label' => 'Finanzas', 'description' => 'Cierres y margen', 'route' => 'finanzas.cierres', 'active' => 'finanzas.*', 'icon' => 'chart-bar', 'accent' => 'bg-lime-600'],
                        ['label' => 'Gastos', 'description' => 'Egresos', 'route' => 'gastos.index', 'active' => 'gastos.*', 'icon' => 'receipt-percent', 'accent' => 'bg-red-500'],
                    ],
                ],
            ];

            $activeItem = collect($navigationGroups)
                ->flatMap(fn (array $group) => $group['items'])
                ->first(fn (array $item) => request()->routeIs($item['active']));
        @endphp

        <flux:sidebar sticky collapsible="mobile" class="app-sidebar border-e-0">
            <flux:sidebar.header class="px-4 pb-2 pt-4">
                <a href="{{ route('dashboard') }}" wire:navigate class="group flex items-center gap-3 rounded-2xl p-2 transition hover:bg-white">
                    <span class="godslove-mark size-11 text-base">
                        <span>GL</span>
                    </span>
                    <span class="min-w-0">
                        <span class="block truncate text-base font-semibold tracking-tight text-slate-950">{{ config('app.name', 'GodsLove') }}</span>
                        <span class="block truncate text-xs font-medium uppercase tracking-[0.18em] text-rose-500">Mas frescas, mas ricas</span>
                    </span>
                </a>

                <flux:sidebar.collapse class="lg:hidden" />
            </flux:sidebar.header>

            @if ($activeItem)
                <div class="px-4 pb-4">
                    <div class="rounded-[1.5rem] border border-white/80 bg-white/80 p-4 shadow-sm shadow-rose-950/5 ring-1 ring-rose-100/70 backdrop-blur">
                        <div class="flex items-start gap-3">
                            <span class="flex size-10 shrink-0 items-center justify-center rounded-xl text-white {{ $activeItem['accent'] }}">
                                <flux:icon :icon="$activeItem['icon']" class="size-4" />
                            </span>
                            <div class="min-w-0">
                                <p class="truncate text-sm font-semibold text-slate-950">{{ $activeItem['label'] }}</p>
                                <p class="mt-1 text-xs leading-5 text-slate-500">{{ $activeItem['description'] }}</p>
                            </div>
                        </div>
                    </div>
                </div>
            @endif

            <flux:sidebar.nav class="px-4">
                <div class="space-y-6">
                    @foreach ($navigationGroups as $group)
                        <nav aria-label="{{ $group['label'] }}" class="space-y-2">
                            <p class="px-3 text-[0.68rem] font-bold uppercase tracking-[0.22em] text-slate-400">
                                {{ $group['label'] }}
                            </p>

                            <div class="space-y-1">
                                @foreach ($group['items'] as $item)
                                    @php
                                        $isActive = request()->routeIs($item['active']);
                                    @endphp

                                    <a
                                        href="{{ route($item['route']) }}"
                                        wire:navigate
                                        @class([
                                            'app-sidebar-link group',
                                            'is-active' => $isActive,
                                        ])
                                        @if ($isActive) aria-current="page" @endif
                                    >
                                        <span class="app-sidebar-link-accent {{ $item['accent'] }}"></span>
                                        <span class="app-sidebar-link-icon">
                                            <flux:icon :icon="$item['icon']" class="size-4" />
                                        </span>
                                        <span class="min-w-0 flex-1">
                                            <span class="block truncate text-sm font-semibold">{{ $item['label'] }}</span>
                                            <span class="block truncate text-xs">{{ $item['description'] }}</span>
                                        </span>
                                    </a>
                                @endforeach
                            </div>
                        </nav>
                    @endforeach
                </div>
            </flux:sidebar.nav>

            <flux:spacer />

            <div class="px-4 pb-4">
                <div class="rounded-[1.5rem] border border-rose-200/80 bg-white/80 p-4 text-slate-950 shadow-sm shadow-rose-950/5 ring-1 ring-rose-100/70 backdrop-blur">
                    <div class="flex items-center justify-between gap-3">
                        <p class="text-xs font-black uppercase tracking-[0.22em] text-rose-500">Caja viva</p>
                        <span class="size-2 rounded-full bg-emerald-500 shadow-[0_0_0_4px_rgba(16,185,129,0.16)]"></span>
                    </div>
                    <p class="mt-2 text-sm leading-5 text-slate-600">Vende, descuenta inventario y revisa el cierre con el toque Godslove.</p>
                    <a href="{{ route('ventas.punto') }}" wire:navigate class="mt-4 inline-flex w-full items-center justify-center rounded-full bg-rose-500 px-3 py-2 text-xs font-black text-white shadow-sm shadow-rose-900/15 transition hover:-translate-y-px hover:bg-rose-600">
                        Ir a vender
                    </a>
                </div>

                <x-desktop-user-menu class="mt-3 hidden lg:block" :name="auth()->user()->name" />
            </div>
        </flux:sidebar>

        <flux:header class="border-b border-white/70 bg-white/80 backdrop-blur lg:hidden">
            <flux:sidebar.toggle class="lg:hidden" icon="bars-2" inset="left" />

            <div class="ml-3 flex items-center gap-2">
                <span class="godslove-mark size-9 text-sm"><span>GL</span></span>
                <div class="min-w-0">
                    <p class="truncate text-sm font-semibold text-slate-950">{{ config('app.name', 'GodsLove') }}</p>
                    <p class="truncate text-xs text-rose-500">Mas frescas, mas ricas</p>
                </div>
            </div>

            <flux:spacer />

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

        @fluxScripts
    </body>
</html>

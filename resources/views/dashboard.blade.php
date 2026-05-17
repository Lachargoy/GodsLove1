@php
    $inicioDia = now()->startOfDay();
    $finDia = now()->endOfDay();

    $cajaAbierta = \App\Models\CorteCaja::query()
        ->abiertaDelDia()
        ->latest('fecha_apertura')
        ->first();

    $ventasHoyQuery = \App\Models\Venta::query()
        ->where('estado', 'pagada')
        ->whereBetween('fecha_venta', [$inicioDia, $finDia]);

    $totalVendidoHoy = round((float) (clone $ventasHoyQuery)->sum('total'), 2);
    $ticketsHoy = (clone $ventasHoyQuery)->count();
    $efectivoHoy = round((float) (clone $ventasHoyQuery)->where('metodo_pago', 'efectivo')->sum('total'), 2);
    $gastosHoy = round((float) \App\Models\Gasto::query()
        ->whereDate('fecha_gasto', today())
        ->where('origen', '!=', 'inversion_extra')
        ->sum('monto'), 2);
    $inversionExtraHoy = round((float) \App\Models\Gasto::query()
        ->whereDate('fecha_gasto', today())
        ->where('origen', 'inversion_extra')
        ->sum('monto'), 2);
    $balanceOperativo = round($totalVendidoHoy - $gastosHoy, 2);
    $ticketPromedio = $ticketsHoy > 0 ? round($totalVendidoHoy / $ticketsHoy, 2) : 0;

    $productosActivos = \App\Models\Producto::query()->where('activo', true)->count();
    $productosConfigurables = \App\Models\Producto::query()
        ->where('activo', true)
        ->where('product_type', 'configurable')
        ->count();

    $insumosBajos = \App\Models\Insumo::query()
        ->where('activo', true)
        ->whereColumn('cantidad_actual', '<=', 'cantidad_minima')
        ->orderBy('cantidad_actual')
        ->limit(5)
        ->get();

    $ultimasVentas = \App\Models\Venta::query()
        ->withCount('detalles')
        ->latest('fecha_venta')
        ->limit(5)
        ->get();
@endphp

<x-layouts::app :title="__('Dashboard')">
    <section class="relative overflow-hidden rounded-[2rem] border border-white/80 bg-slate-950 p-6 text-white shadow-[0_24px_80px_-52px_rgba(15,23,42,0.85)] ring-1 ring-rose-100/40 sm:p-8">
        <div class="absolute inset-x-0 top-0 h-1.5 bg-gradient-to-r from-rose-400 via-amber-300 to-emerald-400"></div>
        <div class="absolute -right-16 -top-20 size-72 rounded-full bg-rose-400/18 blur-3xl"></div>
        <div class="absolute -bottom-24 left-1/2 size-80 rounded-full bg-emerald-300/16 blur-3xl"></div>
        <div class="absolute bottom-0 left-1/2 h-px w-1/2 bg-gradient-to-r from-transparent via-rose-200/40 to-transparent"></div>

        <div class="relative grid gap-8 xl:grid-cols-[1fr_360px] xl:items-end">
            <div>
                <div class="mb-5 inline-flex items-center gap-2 rounded-full border border-white/10 bg-white/10 px-3 py-1 text-xs font-semibold uppercase tracking-[0.18em] text-emerald-100">
                    <span class="size-2 rounded-full {{ $cajaAbierta ? 'bg-emerald-300' : 'bg-amber-300' }}"></span>
                    GodsLove operativo
                </div>

                <h1 class="max-w-4xl text-4xl font-black tracking-normal text-white sm:text-5xl">
                    Mas frescas, mas ricas, mas claras.
                </h1>

                <p class="mt-4 max-w-2xl text-sm leading-6 text-slate-300">
                    Entra, abre caja, vende, revisa inventario bajo y cierra el dia con los numeros enfrente. Una vista suave para trabajar rapido sin perder el encanto Godslove.
                </p>

                <div class="mt-6 flex flex-col gap-3 sm:flex-row">
                    <a href="{{ route('ventas.punto') }}" wire:navigate class="inline-flex items-center justify-center rounded-full bg-rose-400 px-5 py-3 text-sm font-black text-white shadow-sm shadow-rose-950/30 transition hover:-translate-y-px hover:bg-rose-300">
                        Nueva venta
                    </a>
                    <a href="{{ route('caja.corte') }}" wire:navigate class="inline-flex items-center justify-center rounded-full border border-white/15 bg-white/10 px-5 py-3 text-sm font-bold text-white transition hover:-translate-y-px hover:bg-white/15">
                        {{ $cajaAbierta ? 'Ver caja abierta' : 'Abrir caja del dia' }}
                    </a>
                </div>
            </div>

            <div class="rounded-[1.75rem] border border-white/10 bg-white/[0.08] p-5 shadow-inner shadow-white/5 backdrop-blur">
                <p class="text-xs font-semibold uppercase tracking-[0.18em] text-slate-300">Balance operativo de hoy</p>
                <p class="mt-3 text-4xl font-semibold tracking-tight {{ $balanceOperativo < 0 ? 'text-rose-200' : 'text-white' }}">
                    ${{ number_format($balanceOperativo, 2) }}
                </p>
                <div class="mt-5 grid grid-cols-2 gap-3 text-sm">
                    <div class="rounded-2xl bg-white/10 p-3">
                        <p class="text-slate-300">Ventas</p>
                        <p class="mt-1 font-semibold text-white">${{ number_format($totalVendidoHoy, 2) }}</p>
                    </div>
                    <div class="rounded-2xl bg-white/10 p-3">
                        <p class="text-slate-300">Gastos operativos</p>
                        <p class="mt-1 font-semibold text-white">${{ number_format($gastosHoy, 2) }}</p>
                        @if ($inversionExtraHoy > 0)
                            <p class="mt-1 text-xs text-slate-300">Inversion extra: ${{ number_format($inversionExtraHoy, 2) }}</p>
                        @endif
                    </div>
                </div>
            </div>
        </div>
    </section>

    <section class="grid gap-4 md:grid-cols-2 xl:grid-cols-4">
        <article class="app-stat-card">
            <p class="text-xs font-semibold uppercase tracking-[0.16em] text-slate-400">Total vendido</p>
            <p class="mt-3 text-3xl font-semibold tracking-tight text-slate-950">${{ number_format($totalVendidoHoy, 2) }}</p>
            <p class="mt-2 text-sm text-slate-500">{{ $ticketsHoy }} tickets registrados hoy</p>
        </article>

        <article class="app-stat-card">
            <p class="text-xs font-semibold uppercase tracking-[0.16em] text-slate-400">Ticket promedio</p>
            <p class="mt-3 text-3xl font-semibold tracking-tight text-slate-950">${{ number_format($ticketPromedio, 2) }}</p>
            <p class="mt-2 text-sm text-slate-500">Efectivo: ${{ number_format($efectivoHoy, 2) }}</p>
        </article>

        <article class="app-stat-card">
            <p class="text-xs font-semibold uppercase tracking-[0.16em] text-slate-400">Productos activos</p>
            <p class="mt-3 text-3xl font-semibold tracking-tight text-slate-950">{{ $productosActivos }}</p>
            <p class="mt-2 text-sm text-slate-500">{{ $productosConfigurables }} configurables</p>
        </article>

        <article class="app-stat-card">
            <p class="text-xs font-semibold uppercase tracking-[0.16em] text-slate-400">Alertas de stock</p>
            <p class="mt-3 text-3xl font-semibold tracking-tight {{ $insumosBajos->isEmpty() ? 'text-emerald-700' : 'text-amber-700' }}">{{ $insumosBajos->count() }}</p>
            <p class="mt-2 text-sm text-slate-500">{{ $insumosBajos->isEmpty() ? 'Sin insumos bajos' : 'Insumos bajo minimo' }}</p>
        </article>
    </section>

    <section class="grid gap-6 xl:grid-cols-[1.1fr_0.9fr]">
        <div class="app-card">
            <div class="flex flex-col gap-3 border-b border-slate-100 pb-5 sm:flex-row sm:items-end sm:justify-between">
                <div>
                    <p class="text-xs font-semibold uppercase tracking-[0.18em] text-emerald-600">Siguiente accion</p>
                    <h2 class="mt-2 text-2xl font-semibold tracking-tight text-slate-950">
                        {{ $cajaAbierta ? 'Caja lista para vender' : 'Primero abre caja' }}
                    </h2>
                    <p class="mt-2 text-sm leading-6 text-slate-500">
                        {{ $cajaAbierta ? 'La venta quedara ligada al balance del dia y al corte.' : 'Sin caja abierta, el sistema bloquea nuevas ventas para proteger el corte.' }}
                    </p>
                </div>

                <a href="{{ $cajaAbierta ? route('ventas.punto') : route('caja.corte') }}" wire:navigate class="inline-flex items-center justify-center rounded-full bg-slate-950 px-5 py-3 text-sm font-bold text-white transition hover:-translate-y-px hover:bg-slate-800">
                    {{ $cajaAbierta ? 'Ir al POS' : 'Abrir caja' }}
                </a>
            </div>

            <div class="mt-5 grid gap-3 md:grid-cols-2">
                <a href="{{ route('productos.index') }}" wire:navigate class="group app-card-muted">
                    <p class="text-sm font-semibold text-slate-950">Alta de productos</p>
                    <p class="mt-1 text-sm leading-6 text-slate-500">Crea producto simple, con receta o configurable.</p>
                </a>

                <a href="{{ route('productos.recetas') }}" wire:navigate class="group app-card-muted">
                    <p class="text-sm font-semibold text-slate-950">Editar receta y sabores</p>
                    <p class="mt-1 text-sm leading-6 text-slate-500">Configura bolas, consumo y sabores seleccionables.</p>
                </a>

                <a href="{{ route('inventario.movimientos') }}" wire:navigate class="group app-card-muted">
                    <p class="text-sm font-semibold text-slate-950">Movimiento de inventario</p>
                    <p class="mt-1 text-sm leading-6 text-slate-500">Compra, salida o merma con costo promedio.</p>
                </a>

                <a href="{{ route('gastos.index') }}" wire:navigate class="group app-card-muted">
                    <p class="text-sm font-semibold text-slate-950">Registrar gasto</p>
                    <p class="mt-1 text-sm leading-6 text-slate-500">Desde caja del dia o balance general.</p>
                </a>
            </div>
        </div>

        <div class="space-y-6">
            <div class="app-card">
                <div class="flex items-start justify-between gap-4">
                    <div>
                        <p class="text-xs font-semibold uppercase tracking-[0.18em] text-slate-400">Caja del dia</p>
                        <h2 class="mt-2 text-xl font-semibold text-slate-950">
                            {{ $cajaAbierta ? 'Abierta' : 'Sin caja abierta' }}
                        </h2>
                        <p class="mt-2 text-sm leading-6 text-slate-500">
                            @if ($cajaAbierta)
                                Inicio con ${{ number_format((float) $cajaAbierta->monto_inicial, 2) }}.
                            @else
                                Abre caja para permitir ventas y ligar el corte.
                            @endif
                        </p>
                    </div>
                    <span class="rounded-full px-3 py-1 text-xs font-semibold {{ $cajaAbierta ? 'bg-emerald-100 text-emerald-700' : 'bg-amber-100 text-amber-700' }}">
                        {{ $cajaAbierta ? 'Activa' : 'Pendiente' }}
                    </span>
                </div>
            </div>

            <div class="app-card">
                <div class="mb-4 flex items-center justify-between gap-3">
                    <div>
                        <p class="text-xs font-semibold uppercase tracking-[0.18em] text-slate-400">Inventario bajo</p>
                        <h2 class="mt-2 text-xl font-semibold text-slate-950">Revisar antes de vender</h2>
                    </div>
                    <a href="{{ route('insumos.index') }}" wire:navigate class="text-sm font-semibold text-emerald-700 hover:text-emerald-800">Ver insumos</a>
                </div>

                <div class="divide-y divide-slate-100">
                    @forelse ($insumosBajos as $insumo)
                        <div class="flex items-center justify-between gap-3 py-3">
                            <div class="min-w-0">
                                <p class="truncate text-sm font-semibold text-slate-950">{{ $insumo->nombre }}</p>
                                <p class="text-xs text-slate-500">Minimo {{ number_format((float) $insumo->cantidad_minima, 3) }} {{ $insumo->unidad_medida }}</p>
                            </div>
                            <span class="whitespace-nowrap rounded-full bg-amber-50 px-3 py-1 text-xs font-semibold text-amber-700">
                                {{ number_format((float) $insumo->cantidad_actual, 3) }}
                            </span>
                        </div>
                    @empty
                        <div class="rounded-2xl bg-emerald-50 px-4 py-5 text-sm text-emerald-800">
                            Sin alertas de inventario bajo por ahora.
                        </div>
                    @endforelse
                </div>
            </div>
        </div>
    </section>

    <section class="app-card">
        <div class="mb-4 flex flex-col gap-3 sm:flex-row sm:items-end sm:justify-between">
            <div>
                <p class="text-xs font-semibold uppercase tracking-[0.18em] text-slate-400">Actividad reciente</p>
                <h2 class="mt-2 text-xl font-semibold text-slate-950">Ultimas ventas</h2>
            </div>
            <a href="{{ route('ventas.punto') }}" wire:navigate class="text-sm font-semibold text-emerald-700 hover:text-emerald-800">Abrir punto de venta</a>
        </div>

        <div class="overflow-x-auto">
            <table class="min-w-full table-fixed text-left text-sm">
                <thead>
                    <tr class="border-b border-slate-200 text-xs uppercase tracking-[0.14em] text-slate-400">
                        <th class="w-36 px-3 py-3 font-semibold">Folio</th>
                        <th class="w-36 px-3 py-3 font-semibold">Hora</th>
                        <th class="w-32 px-3 py-3 font-semibold">Metodo</th>
                        <th class="w-28 px-3 py-3 text-right font-semibold">Items</th>
                        <th class="w-36 px-3 py-3 text-right font-semibold">Total</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100">
                    @forelse ($ultimasVentas as $venta)
                        <tr>
                            <td class="px-3 py-3 font-semibold text-slate-950">{{ $venta->folio }}</td>
                            <td class="whitespace-nowrap px-3 py-3 text-slate-500">{{ optional($venta->fecha_venta)->format('d/m H:i') ?? 'Sin hora' }}</td>
                            <td class="whitespace-nowrap px-3 py-3 capitalize text-slate-600">{{ $venta->metodo_pago }}</td>
                            <td class="whitespace-nowrap px-3 py-3 text-right text-slate-600">{{ $venta->detalles_count }}</td>
                            <td class="whitespace-nowrap px-3 py-3 text-right font-semibold text-emerald-700">${{ number_format((float) $venta->total, 2) }}</td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="5" class="px-3 py-8 text-center text-sm text-slate-500">
                                Todavia no hay ventas registradas.
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </section>
</x-layouts::app>

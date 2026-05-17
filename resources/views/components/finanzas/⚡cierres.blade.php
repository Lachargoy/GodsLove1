<?php

use App\Models\CorteCaja;
use App\Models\Gasto;
use App\Models\Venta;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Carbon;
use Livewire\Component;

new class extends Component
{
    public string $periodo = 'semana';
    public string $fecha_desde = '';
    public string $fecha_hasta = '';

    public function mount(): void
    {
        $this->aplicarPeriodo('semana');
    }

    public function getVentasAnalisisProperty(): EloquentCollection
    {
        [$inicio, $fin] = $this->resolverRangoAnalisis();

        return Venta::query()
            ->with(['user', 'detalles.producto'])
            ->where('estado', 'pagada')
            ->whereBetween('fecha_venta', [$inicio, $fin])
            ->orderByDesc('fecha_venta')
            ->get();
    }

    public function getGastosAnalisisProperty(): EloquentCollection
    {
        [$inicio, $fin] = $this->resolverRangoAnalisis();

        return Gasto::query()
            ->with(['categoria', 'user', 'corteCaja'])
            ->whereBetween('fecha_gasto', [$inicio->toDateString(), $fin->toDateString()])
            ->orderByDesc('fecha_gasto')
            ->orderByDesc('created_at')
            ->get();
    }

    public function getCortesPeriodoProperty(): EloquentCollection
    {
        [$inicio, $fin] = $this->resolverRangoAnalisis();

        return CorteCaja::query()
            ->with('user')
            ->where(function ($query) use ($inicio, $fin) {
                $query
                    ->whereBetween('fecha_apertura', [$inicio, $fin])
                    ->orWhereBetween('fecha_cierre', [$inicio, $fin]);
            })
            ->orderByDesc('fecha_apertura')
            ->get();
    }

    public function getVentasPorMetodoProperty(): array
    {
        return [
            'efectivo' => $this->sumarVentasPorMetodo($this->ventasAnalisis, 'efectivo'),
            'tarjeta' => $this->sumarVentasPorMetodo($this->ventasAnalisis, 'tarjeta'),
            'transferencia' => $this->sumarVentasPorMetodo($this->ventasAnalisis, 'transferencia'),
            'mixto' => $this->sumarVentasPorMetodo($this->ventasAnalisis, 'mixto'),
        ];
    }

    public function getTotalIngresosProperty(): float
    {
        return round((float) $this->ventasAnalisis->sum('total'), 2);
    }

    public function getTicketsProperty(): int
    {
        return $this->ventasAnalisis->count();
    }

    public function getDescuentosProperty(): float
    {
        return round((float) $this->ventasAnalisis->sum('descuento'), 2);
    }

    public function getPromedioTicketProperty(): float
    {
        if ($this->tickets === 0) {
            return 0;
        }

        return round($this->totalIngresos / $this->tickets, 2);
    }

    public function getTotalGastosProperty(): float
    {
        return round((float) $this->gastosAnalisis->sum('monto'), 2);
    }

    public function getGastosOperativosProperty(): float
    {
        return round(
            (float) $this->gastosAnalisis
                ->where('origen', '!=', 'inversion_extra')
                ->sum('monto'),
            2,
        );
    }

    public function getGastosCajaDiaProperty(): float
    {
        return round((float) $this->gastosAnalisis->where('origen', 'caja_dia')->sum('monto'), 2);
    }

    public function getGastosBalanceGeneralProperty(): float
    {
        return round((float) $this->gastosAnalisis->where('origen', 'balance_general')->sum('monto'), 2);
    }

    public function getGastosInversionExtraProperty(): float
    {
        return round((float) $this->gastosAnalisis->where('origen', 'inversion_extra')->sum('monto'), 2);
    }

    public function getGastosFijosProperty(): float
    {
        return round(
            (float) $this->gastosAnalisis
                ->where('origen', '!=', 'inversion_extra')
                ->where('tipo', 'fijo')
                ->sum('monto'),
            2,
        );
    }

    public function getGastosVariablesProperty(): float
    {
        return round(
            (float) $this->gastosAnalisis
                ->where('origen', '!=', 'inversion_extra')
                ->where('tipo', 'variable')
                ->sum('monto'),
            2,
        );
    }

    public function getBalanceProperty(): float
    {
        return round($this->totalIngresos - $this->gastosOperativos, 2);
    }

    public function getPeriodoEtiquetaProperty(): string
    {
        return match ($this->periodo) {
            'hoy' => 'Hoy',
            'mes' => 'Este mes',
            default => 'Esta semana',
        };
    }

    public function aplicarPeriodo(string $periodo): void
    {
        $this->periodo = in_array($periodo, ['hoy', 'semana', 'mes'], true) ? $periodo : 'semana';

        $ahora = now();

        if ($this->periodo === 'hoy') {
            $this->fecha_desde = $ahora->copy()->startOfDay()->toDateString();
            $this->fecha_hasta = $ahora->copy()->endOfDay()->toDateString();

            return;
        }

        if ($this->periodo === 'mes') {
            $this->fecha_desde = $ahora->copy()->startOfMonth()->toDateString();
            $this->fecha_hasta = $ahora->copy()->endOfMonth()->toDateString();

            return;
        }

        $this->fecha_desde = $ahora->copy()->startOfWeek()->toDateString();
        $this->fecha_hasta = $ahora->copy()->endOfWeek()->toDateString();
    }

    public function actualizarRango(): void
    {
        $this->validate([
            'fecha_desde' => ['required', 'date'],
            'fecha_hasta' => ['required', 'date', 'after_or_equal:fecha_desde'],
        ]);

        $this->periodo = 'personalizado';
    }

    private function sumarVentasPorMetodo(EloquentCollection $ventas, string $metodo): float
    {
        return round((float) $ventas->where('metodo_pago', $metodo)->sum('total'), 2);
    }

    /**
     * @return array{0: Carbon, 1: Carbon}
     */
    private function resolverRangoAnalisis(): array
    {
        $inicio = $this->fecha_desde !== ''
            ? Carbon::parse($this->fecha_desde)->startOfDay()
            : now()->startOfWeek();

        $fin = $this->fecha_hasta !== ''
            ? Carbon::parse($this->fecha_hasta)->endOfDay()
            : now()->endOfWeek();

        if ($fin->lessThan($inicio)) {
            $fin = $inicio->copy()->endOfDay();
        }

        return [$inicio, $fin];
    }
};
?>

<div class="space-y-6">
    <div class="flex flex-col gap-2 sm:flex-row sm:items-end sm:justify-between">
        <div>
            <h1 class="text-2xl font-bold text-slate-900">Finanzas y cierres</h1>
            <p class="text-sm text-slate-500">
                Analiza cierres semanales y mensuales con calendario, ingresos, gastos fijos y balance del negocio.
            </p>
        </div>

        <a
            href="{{ route('caja.corte') }}"
            wire:navigate
            class="inline-flex items-center rounded-xl border border-slate-300 px-4 py-2 text-sm font-medium text-slate-700 transition hover:bg-slate-50"
        >
            Volver a caja
        </a>
    </div>

    <div class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm">
        <div class="mb-4 flex flex-col gap-4 xl:flex-row xl:items-end xl:justify-between">
            <div>
                <h2 class="text-lg font-semibold text-slate-900">Cierres formales por periodo</h2>
                <p class="text-sm text-slate-500">Separa el control del turno diario y revisa el comportamiento financiero de la semana o del mes.</p>
            </div>

            <div class="flex flex-wrap gap-2">
                <button
                    type="button"
                    wire:click="aplicarPeriodo('hoy')"
                    class="rounded-xl px-3 py-2 text-sm font-medium {{ $periodo === 'hoy' ? 'bg-slate-900 text-white' : 'border border-slate-300 text-slate-700 hover:bg-slate-50' }}"
                >
                    Hoy
                </button>
                <button
                    type="button"
                    wire:click="aplicarPeriodo('semana')"
                    class="rounded-xl px-3 py-2 text-sm font-medium {{ $periodo === 'semana' ? 'bg-slate-900 text-white' : 'border border-slate-300 text-slate-700 hover:bg-slate-50' }}"
                >
                    Semana
                </button>
                <button
                    type="button"
                    wire:click="aplicarPeriodo('mes')"
                    class="rounded-xl px-3 py-2 text-sm font-medium {{ $periodo === 'mes' ? 'bg-slate-900 text-white' : 'border border-slate-300 text-slate-700 hover:bg-slate-50' }}"
                >
                    Mes
                </button>
            </div>
        </div>

        <form wire:submit="actualizarRango" class="grid gap-4 md:grid-cols-3 xl:grid-cols-5">
            <div>
                <label class="mb-1 block text-sm font-medium text-slate-700">Desde</label>
                <input type="date" wire:model="fecha_desde" class="w-full rounded-xl border-slate-300 text-sm">
                @error('fecha_desde')
                    <p class="mt-1 text-sm text-red-600">{{ $message }}</p>
                @enderror
            </div>

            <div>
                <label class="mb-1 block text-sm font-medium text-slate-700">Hasta</label>
                <input type="date" wire:model="fecha_hasta" class="w-full rounded-xl border-slate-300 text-sm">
                @error('fecha_hasta')
                    <p class="mt-1 text-sm text-red-600">{{ $message }}</p>
                @enderror
            </div>

            <div class="md:col-span-3 xl:col-span-1 xl:self-end">
                <button
                    type="submit"
                    class="inline-flex items-center rounded-xl border border-slate-300 px-4 py-2 text-sm font-medium text-slate-700 transition hover:bg-slate-50"
                >
                    Aplicar calendario
                </button>
            </div>
        </form>

        <div class="mt-4 rounded-xl bg-slate-50 p-4 text-sm text-slate-600">
            Rango actual:
            <span class="font-medium text-slate-900">{{ $this->periodoEtiqueta }}</span>
            del {{ \Illuminate\Support\Carbon::parse($fecha_desde)->format('d/m/Y') }}
            al {{ \Illuminate\Support\Carbon::parse($fecha_hasta)->format('d/m/Y') }}.
        </div>
    </div>

    <div class="grid gap-4 md:grid-cols-2 xl:grid-cols-4">
        <div class="rounded-2xl border border-emerald-200 bg-emerald-50 p-5 shadow-sm">
            <p class="text-sm text-emerald-700">Ingresos del periodo</p>
            <p class="mt-2 text-3xl font-semibold text-emerald-900">${{ number_format($this->totalIngresos, 2) }}</p>
            <p class="mt-1 text-xs text-emerald-700">Tickets: {{ $this->tickets }}</p>
        </div>

        <div class="rounded-2xl border border-rose-200 bg-rose-50 p-5 shadow-sm">
            <p class="text-sm text-rose-700">Gastos operativos</p>
            <p class="mt-2 text-3xl font-semibold text-rose-900">${{ number_format($this->gastosOperativos, 2) }}</p>
            <p class="mt-1 text-xs text-rose-700">Total salidas: ${{ number_format($this->totalGastos, 2) }}</p>
        </div>

        <div class="rounded-2xl border border-sky-200 bg-sky-50 p-5 shadow-sm">
            <p class="text-sm text-sky-700">Ticket promedio</p>
            <p class="mt-2 text-3xl font-semibold text-sky-900">${{ number_format($this->promedioTicket, 2) }}</p>
        </div>

        <div class="rounded-2xl border border-amber-200 bg-amber-50 p-5 shadow-sm">
            <p class="text-sm text-amber-700">Balance operativo</p>
            <p class="mt-2 text-3xl font-semibold {{ $this->balance < 0 ? 'text-red-700' : 'text-amber-900' }}">
                ${{ number_format($this->balance, 2) }}
            </p>
            <p class="mt-1 text-xs text-amber-700">Ingresos - gastos operativos</p>
        </div>
    </div>

    <div class="grid gap-6 xl:grid-cols-[1.15fr_0.85fr]">
        <div class="space-y-6">
            <div class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm">
                <div class="mb-4">
                    <h2 class="text-lg font-semibold text-slate-900">Entradas del periodo</h2>
                    <p class="text-sm text-slate-500">Cada fila te muestra el folio, el metodo de pago y el detalle de lo que entro por venta.</p>
                </div>

                <div class="overflow-x-auto">
                    <table class="min-w-full border-collapse text-left text-sm">
                        <thead>
                            <tr class="border-b border-slate-200 bg-slate-50 text-slate-600">
                                <th class="p-3 font-medium">Folio</th>
                                <th class="p-3 font-medium">Fecha</th>
                                <th class="p-3 font-medium">Metodo</th>
                                <th class="p-3 font-medium">Detalle de entrada</th>
                                <th class="p-3 font-medium">Total</th>
                            </tr>
                        </thead>
                        <tbody>
                            @forelse ($this->ventasAnalisis->take(20) as $venta)
                                <tr wire:key="finanza-venta-{{ $venta->id }}" class="border-b border-slate-100 align-top">
                                    <td class="p-3 font-medium text-slate-900">{{ $venta->folio }}</td>
                                    <td class="p-3 text-slate-700">{{ \Illuminate\Support\Carbon::parse($venta->fecha_venta)->format('d/m/Y H:i') }}</td>
                                    <td class="p-3 text-slate-700 capitalize">{{ $venta->metodo_pago }}</td>
                                    <td class="p-3 text-slate-700">
                                        @foreach ($venta->detalles as $detalle)
                                            <p>{{ $detalle->producto?->nombre ?? 'Producto eliminado' }} x {{ number_format((float) $detalle->cantidad, 2) }}</p>
                                        @endforeach
                                    </td>
                                    <td class="p-3 font-medium text-emerald-700">${{ number_format((float) $venta->total, 2) }}</td>
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="5" class="p-4">
                                        <div class="godslove-empty">
                                            <p class="godslove-empty-title">No hay ventas en este rango</p>
                                            <p class="godslove-empty-copy">Ajusta las fechas o registra ventas para analizar el periodo.</p>
                                        </div>
                                    </td>
                                </tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </div>

            <div class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm">
                <div class="mb-4">
                    <h2 class="text-lg font-semibold text-slate-900">Salidas y gastos del periodo</h2>
                    <p class="text-sm text-slate-500">Aqui queda claro en que se gasto, si fue fijo o variable, y desde que origen salio.</p>
                </div>

                <div class="overflow-x-auto">
                    <table class="min-w-full border-collapse text-left text-sm">
                        <thead>
                            <tr class="border-b border-slate-200 bg-slate-50 text-slate-600">
                                <th class="p-3 font-medium">Fecha</th>
                                <th class="p-3 font-medium">Descripcion</th>
                                <th class="p-3 font-medium">Categoria</th>
                                <th class="p-3 font-medium">Origen</th>
                                <th class="p-3 font-medium">Tipo</th>
                                <th class="p-3 font-medium">Caja</th>
                                <th class="p-3 font-medium">Monto</th>
                            </tr>
                        </thead>
                        <tbody>
                            @forelse ($this->gastosAnalisis->take(20) as $gasto)
                                <tr wire:key="finanza-gasto-{{ $gasto->id }}" class="border-b border-slate-100">
                                    <td class="p-3 text-slate-700">{{ \Illuminate\Support\Carbon::parse($gasto->fecha_gasto)->format('d/m/Y') }}</td>
                                    <td class="p-3 font-medium text-slate-900">{{ $gasto->descripcion }}</td>
                                    <td class="p-3 text-slate-700">{{ $gasto->categoria?->nombre ?? 'Sin categoria' }}</td>
                                    <td class="p-3 text-slate-700">
                                        <span class="whitespace-nowrap rounded-full px-2.5 py-1 text-xs font-semibold {{ $gasto->origen === 'caja_dia' ? 'bg-emerald-50 text-emerald-700' : ($gasto->origen === 'inversion_extra' ? 'bg-sky-50 text-sky-700' : 'bg-slate-100 text-slate-600') }}">
                                            {{ str_replace('_', ' ', $gasto->origen ?? 'balance_general') }}
                                        </span>
                                    </td>
                                    <td class="p-3 text-slate-700 capitalize">{{ $gasto->tipo }}</td>
                                    <td class="p-3 text-slate-700">
                                        {{ $gasto->corteCaja?->fecha_apertura ? 'Caja '.\Illuminate\Support\Carbon::parse($gasto->corteCaja->fecha_apertura)->format('d/m H:i') : 'Sin caja' }}
                                    </td>
                                    <td class="p-3 font-medium text-rose-700">${{ number_format((float) $gasto->monto, 2) }}</td>
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="7" class="p-4">
                                        <div class="godslove-empty">
                                            <p class="godslove-empty-title">No hay gastos en este rango</p>
                                            <p class="godslove-empty-copy">Ajusta las fechas o registra egresos para analizarlos aqui.</p>
                                        </div>
                                    </td>
                                </tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <div class="space-y-6">
            <div class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm">
                <div class="mb-4">
                    <h2 class="text-lg font-semibold text-slate-900">Resumen por metodo y tipo</h2>
                    <p class="text-sm text-slate-500">Te ayuda a leer rapido si el negocio esta dejando caja o solo movimiento digital.</p>
                </div>

                <div class="grid gap-3">
                    <div class="rounded-xl border border-slate-200 bg-slate-50 p-4">
                        <p class="text-xs font-medium text-slate-500">Ventas por metodo</p>
                        <div class="mt-3 space-y-2 text-sm text-slate-700">
                            <div class="flex items-center justify-between"><span>Efectivo</span><span>${{ number_format($this->ventasPorMetodo['efectivo'], 2) }}</span></div>
                            <div class="flex items-center justify-between"><span>Tarjeta</span><span>${{ number_format($this->ventasPorMetodo['tarjeta'], 2) }}</span></div>
                            <div class="flex items-center justify-between"><span>Transferencia</span><span>${{ number_format($this->ventasPorMetodo['transferencia'], 2) }}</span></div>
                            <div class="flex items-center justify-between"><span>Mixto</span><span>${{ number_format($this->ventasPorMetodo['mixto'], 2) }}</span></div>
                        </div>
                    </div>

                    <div class="rounded-xl border border-slate-200 bg-slate-50 p-4">
                        <p class="text-xs font-medium text-slate-500">Gastos operativos por tipo</p>
                        <div class="mt-3 space-y-2 text-sm text-slate-700">
                            <div class="flex items-center justify-between"><span>Fijos</span><span>${{ number_format($this->gastosFijos, 2) }}</span></div>
                            <div class="flex items-center justify-between"><span>Variables</span><span>${{ number_format($this->gastosVariables, 2) }}</span></div>
                        </div>
                    </div>

                    <div class="rounded-xl border border-slate-200 bg-slate-50 p-4">
                        <p class="text-xs font-medium text-slate-500">Gastos por origen</p>
                        <div class="mt-3 space-y-2 text-sm text-slate-700">
                            <div class="flex items-center justify-between"><span>Caja del dia</span><span>${{ number_format($this->gastosCajaDia, 2) }}</span></div>
                            <div class="flex items-center justify-between"><span>Balance general</span><span>${{ number_format($this->gastosBalanceGeneral, 2) }}</span></div>
                            <div class="flex items-center justify-between"><span>Inversion extra</span><span>${{ number_format($this->gastosInversionExtra, 2) }}</span></div>
                        </div>
                    </div>
                </div>
            </div>

            <div class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm">
                <div class="mb-4">
                    <h2 class="text-lg font-semibold text-slate-900">Cortes diarios incluidos</h2>
                    <p class="text-sm text-slate-500">Estos son los cortes diarios que alimentan tu lectura semanal o mensual.</p>
                </div>

                <div class="overflow-x-auto">
                    <table class="min-w-full border-collapse text-left text-sm">
                        <thead>
                            <tr class="border-b border-slate-200 bg-slate-50 text-slate-600">
                                <th class="p-3 font-medium">Apertura</th>
                                <th class="p-3 font-medium">Cierre</th>
                                <th class="p-3 font-medium">Usuario</th>
                                <th class="p-3 font-medium">Esperado</th>
                                <th class="p-3 font-medium">Real</th>
                            </tr>
                        </thead>
                        <tbody>
                            @forelse ($this->cortesPeriodo->take(10) as $corte)
                                <tr wire:key="corte-periodo-{{ $corte->id }}" class="border-b border-slate-100">
                                    <td class="p-3 text-slate-700">{{ $corte->fecha_apertura ? \Illuminate\Support\Carbon::parse($corte->fecha_apertura)->format('d/m/Y H:i') : 'Sin apertura' }}</td>
                                    <td class="p-3 text-slate-700">{{ $corte->fecha_cierre ? \Illuminate\Support\Carbon::parse($corte->fecha_cierre)->format('d/m/Y H:i') : 'Abierta' }}</td>
                                    <td class="p-3 text-slate-700">{{ $corte->user?->name ?? 'Sin usuario' }}</td>
                                    <td class="p-3 text-slate-700">${{ number_format((float) $corte->monto_esperado, 2) }}</td>
                                    <td class="p-3 text-slate-700">${{ number_format((float) $corte->monto_real, 2) }}</td>
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="5" class="p-4">
                                        <div class="godslove-empty">
                                            <p class="godslove-empty-title">No hay cortes en este rango</p>
                                            <p class="godslove-empty-copy">Los cierres de caja alimentan esta lectura financiera.</p>
                                        </div>
                                    </td>
                                </tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
</div>

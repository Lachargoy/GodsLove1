<?php

use App\Models\CorteCaja;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Livewire\Component;

new class extends Component
{
    public string $monto_inicial = '';
    public string $monto_real = '';
    public string $observaciones = '';

    public function getCajaAbiertaProperty(): ?CorteCaja
    {
        return CorteCaja::query()
            ->with('user')
            ->where('estado', 'abierto')
            ->latest('fecha_apertura')
            ->first();
    }

    public function getVentasTurnoProperty(): EloquentCollection
    {
        if (! $this->cajaAbierta instanceof CorteCaja) {
            return new EloquentCollection();
        }

        return $this->cajaAbierta
            ->ventas()
            ->with(['user', 'detalles.producto'])
            ->where('estado', 'pagada')
            ->orderByDesc('fecha_venta')
            ->get();
    }

    public function getGastosTurnoDetallesProperty(): EloquentCollection
    {
        if (! $this->cajaAbierta instanceof CorteCaja) {
            return new EloquentCollection();
        }

        return $this->cajaAbierta
            ->gastos()
            ->with(['categoria', 'user'])
            ->where('origen', 'caja_dia')
            ->latest('created_at')
            ->get();
    }

    public function getVentasEfectivoProperty(): float
    {
        return $this->sumarVentasPorMetodo($this->ventasTurno, 'efectivo');
    }

    public function getVentasTarjetaProperty(): float
    {
        return $this->sumarVentasPorMetodo($this->ventasTurno, 'tarjeta');
    }

    public function getVentasTransferenciaProperty(): float
    {
        return $this->sumarVentasPorMetodo($this->ventasTurno, 'transferencia');
    }

    public function getVentasMixtoProperty(): float
    {
        return $this->sumarVentasPorMetodo($this->ventasTurno, 'mixto');
    }

    public function getTotalVentasTurnoProperty(): float
    {
        return round((float) $this->ventasTurno->sum('total'), 2);
    }

    public function getTicketsTurnoProperty(): int
    {
        return $this->ventasTurno->count();
    }

    public function getGastosTurnoProperty(): float
    {
        return round((float) $this->gastosTurnoDetalles->sum('monto'), 2);
    }

    public function getMontoEsperadoProperty(): float
    {
        if (! $this->cajaAbierta instanceof CorteCaja) {
            return 0;
        }

        return round(
            (float) $this->cajaAbierta->monto_inicial + $this->ventasEfectivo - $this->gastosTurno,
            2,
        );
    }

    public function getHistorialCortesProperty(): EloquentCollection
    {
        return CorteCaja::query()
            ->with('user')
            ->latest('fecha_apertura')
            ->limit(10)
            ->get();
    }

    public function abrirCaja(): void
    {
        $validated = $this->validate([
            'monto_inicial' => ['required', 'numeric', 'min:0'],
        ]);

        if ($this->cajaAbierta instanceof CorteCaja) {
            session()->flash('error', 'Ya existe una caja abierta.');

            return;
        }

        CorteCaja::query()->create([
            'user_id' => auth()->id(),
            'fecha_apertura' => now(),
            'monto_inicial' => round((float) $validated['monto_inicial'], 2),
            'estado' => 'abierto',
        ]);

        $this->reset('monto_inicial');

        session()->flash('success', 'Caja abierta correctamente.');
    }

    public function cerrarCaja(): void
    {
        if (! $this->cajaAbierta instanceof CorteCaja) {
            session()->flash('error', 'No hay una caja abierta para cerrar.');

            return;
        }

        $validated = $this->validate([
            'monto_real' => ['required', 'numeric', 'min:0'],
            'observaciones' => ['nullable', 'string'],
        ]);

        $montoReal = round((float) $validated['monto_real'], 2);
        $montoEsperado = $this->montoEsperado;
        $diferencia = round($montoReal - $montoEsperado, 2);

        $this->cajaAbierta->update([
            'fecha_cierre' => now(),
            'ventas_efectivo' => $this->ventasEfectivo,
            'ventas_tarjeta' => $this->ventasTarjeta,
            'ventas_transferencia' => $this->ventasTransferencia,
            'gastos_turno' => $this->gastosTurno,
            'monto_esperado' => $montoEsperado,
            'monto_real' => $montoReal,
            'diferencia' => $diferencia,
            'estado' => 'cerrado',
            'observaciones' => $validated['observaciones'] ?: null,
        ]);

        $this->reset([
            'monto_real',
            'observaciones',
        ]);

        session()->flash('success', 'Caja cerrada correctamente.');
    }

    private function sumarVentasPorMetodo(EloquentCollection $ventas, string $metodo): float
    {
        return round((float) $ventas->where('metodo_pago', $metodo)->sum('total'), 2);
    }
};
?>

<div class="space-y-6">
    <div class="flex flex-col gap-2 sm:flex-row sm:items-end sm:justify-between">
        <div>
            <h1 class="text-2xl font-bold text-gray-900">Corte de caja</h1>
            <p class="text-sm text-gray-500">
                Abre, controla y cierra la caja del turno.
            </p>
        </div>

        <a
            href="{{ route('gastos.index') }}"
            wire:navigate
            class="inline-flex items-center rounded-xl border border-slate-300 px-4 py-2 text-sm font-medium text-slate-700 transition hover:bg-slate-50"
        >
            Registrar gasto
        </a>

        <a
            href="{{ route('finanzas.cierres') }}"
            wire:navigate
            class="inline-flex items-center rounded-xl border border-slate-300 px-4 py-2 text-sm font-medium text-slate-700 transition hover:bg-slate-50"
        >
            Ver finanzas
        </a>
    </div>

    @if (! $this->cajaAbierta)
        <div class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm">
            <div class="mb-4">
                <h2 class="text-lg font-semibold text-slate-900">Abrir caja</h2>
                <p class="text-sm text-slate-500">Registra el monto inicial del turno antes de empezar a vender.</p>
            </div>

            <form wire:submit="abrirCaja" class="grid gap-4 md:max-w-md">
                <div>
                    <label class="mb-1 block text-sm font-medium text-slate-700">Monto inicial</label>
                    <input
                        type="number"
                        step="0.01"
                        wire:model="monto_inicial"
                        class="w-full rounded-xl border-slate-300 text-sm"
                        placeholder="0.00"
                    >

                    @error('monto_inicial')
                        <p class="mt-1 text-sm text-red-600">{{ $message }}</p>
                    @enderror
                </div>

                <button
                    type="submit"
                    class="inline-flex items-center justify-center rounded-xl bg-slate-900 px-4 py-2 text-sm font-medium text-white transition hover:bg-slate-800"
                >
                    Abrir caja
                </button>
            </form>
        </div>
    @else
        <div class="grid gap-4 md:grid-cols-2 xl:grid-cols-4">
            <div class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm">
                <p class="text-sm text-slate-500">Fecha de apertura</p>
                <p class="mt-2 text-lg font-semibold text-slate-900">
                    {{ \Illuminate\Support\Carbon::parse($this->cajaAbierta->fecha_apertura)->format('d/m/Y H:i') }}
                </p>
                <p class="mt-1 text-xs text-slate-500">Caja abierta por {{ $this->cajaAbierta->user?->name ?? 'Sin usuario' }}</p>
            </div>

            <div class="rounded-2xl border border-sky-200 bg-sky-50 p-5 shadow-sm">
                <p class="text-sm text-sky-700">Monto inicial</p>
                <p class="mt-2 text-3xl font-semibold text-sky-900">${{ number_format((float) $this->cajaAbierta->monto_inicial, 2) }}</p>
            </div>

            <div class="rounded-2xl border border-emerald-200 bg-emerald-50 p-5 shadow-sm">
                <p class="text-sm text-emerald-700">Total ventas turno</p>
                <p class="mt-2 text-3xl font-semibold text-emerald-900">${{ number_format($this->totalVentasTurno, 2) }}</p>
                <p class="mt-1 text-xs text-emerald-700">Tickets: {{ $this->ticketsTurno }}</p>
            </div>

            <div class="rounded-2xl border border-amber-200 bg-amber-50 p-5 shadow-sm">
                <p class="text-sm text-amber-700">Monto esperado efectivo</p>
                <p class="mt-2 text-3xl font-semibold text-amber-900">${{ number_format($this->montoEsperado, 2) }}</p>
                <p class="mt-1 text-xs text-amber-700">Inicial + efectivo - gastos</p>
            </div>
        </div>

        <div class="grid gap-6 xl:grid-cols-[1.2fr_0.8fr]">
            <div class="space-y-6">
                <div class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm">
                    <div class="mb-4">
                        <h2 class="text-lg font-semibold text-slate-900">Resumen del turno</h2>
                        <p class="text-sm text-slate-500">Controla lo que entra y sale en esta caja mientras el turno sigue abierto.</p>
                    </div>

                    <div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-3">
                        <div class="rounded-xl border border-emerald-200 bg-emerald-50 p-4">
                            <p class="text-xs font-medium text-emerald-700">Entradas en efectivo</p>
                            <p class="mt-2 text-xl font-semibold text-emerald-900">${{ number_format($this->ventasEfectivo, 2) }}</p>
                        </div>

                        <div class="rounded-xl border border-slate-200 bg-slate-50 p-4">
                            <p class="text-xs font-medium text-slate-500">Entradas con tarjeta</p>
                            <p class="mt-2 text-xl font-semibold text-slate-900">${{ number_format($this->ventasTarjeta, 2) }}</p>
                        </div>

                        <div class="rounded-xl border border-slate-200 bg-slate-50 p-4">
                            <p class="text-xs font-medium text-slate-500">Entradas por transferencia</p>
                            <p class="mt-2 text-xl font-semibold text-slate-900">${{ number_format($this->ventasTransferencia, 2) }}</p>
                        </div>

                        <div class="rounded-xl border border-slate-200 bg-slate-50 p-4">
                            <p class="text-xs font-medium text-slate-500">Entradas mixtas</p>
                            <p class="mt-2 text-xl font-semibold text-slate-900">${{ number_format($this->ventasMixto, 2) }}</p>
                        </div>

                        <div class="rounded-xl border border-rose-200 bg-rose-50 p-4">
                            <p class="text-xs font-medium text-rose-700">Salidas por gastos</p>
                            <p class="mt-2 text-xl font-semibold text-rose-900">${{ number_format($this->gastosTurno, 2) }}</p>
                        </div>

                        <div class="rounded-xl border border-amber-200 bg-amber-50 p-4">
                            <p class="text-xs font-medium text-amber-700">Efectivo esperado</p>
                            <p class="mt-2 text-xl font-semibold text-amber-900">${{ number_format($this->montoEsperado, 2) }}</p>
                        </div>
                    </div>
                </div>

                <div class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm">
                    <div class="mb-4 flex flex-col gap-2 sm:flex-row sm:items-end sm:justify-between">
                        <div>
                            <h2 class="text-lg font-semibold text-slate-900">Detalle de entradas del turno</h2>
                            <p class="text-sm text-slate-500">Cada venta queda ligada a la caja abierta y muestra qué producto entró al corte.</p>
                        </div>
                        <span class="rounded-full bg-emerald-50 px-3 py-1 text-xs font-medium text-emerald-700">
                            {{ $this->ventasTurno->count() }} ventas ligadas a esta caja
                        </span>
                    </div>

                    <div class="overflow-x-auto">
                        <table class="min-w-full border-collapse text-left text-sm">
                            <thead>
                                <tr class="border-b border-slate-200 bg-slate-50 text-slate-600">
                                    <th class="p-3 font-medium">Folio</th>
                                    <th class="p-3 font-medium">Fecha</th>
                                    <th class="p-3 font-medium">Metodo</th>
                                    <th class="p-3 font-medium">Productos</th>
                                    <th class="p-3 font-medium">Detalle de entrada</th>
                                    <th class="p-3 font-medium">Total</th>
                                </tr>
                            </thead>
                            <tbody>
                                @forelse ($this->ventasTurno as $venta)
                                    <tr wire:key="venta-turno-{{ $venta->id }}" class="border-b border-slate-100 align-top">
                                        <td class="p-3 font-medium text-slate-900">{{ $venta->folio }}</td>
                                        <td class="p-3 text-slate-700">
                                            {{ \Illuminate\Support\Carbon::parse($venta->fecha_venta)->format('d/m/Y H:i') }}
                                        </td>
                                        <td class="p-3 text-slate-700 capitalize">{{ $venta->metodo_pago }}</td>
                                        <td class="p-3 text-slate-700">
                                            {{ number_format((float) $venta->detalles->sum('cantidad'), 2) }} items
                                        </td>
                                        <td class="p-3 text-slate-700">
                                            <div class="space-y-1">
                                                @foreach ($venta->detalles as $detalle)
                                                    <p>
                                                        {{ $detalle->producto?->nombre ?? 'Producto eliminado' }}
                                                        <span class="text-slate-500">
                                                            x {{ number_format((float) $detalle->cantidad, 2) }}
                                                        </span>
                                                    </p>
                                                @endforeach
                                            </div>
                                        </td>
                                        <td class="p-3 font-medium text-emerald-700">${{ number_format((float) $venta->total, 2) }}</td>
                                    </tr>
                                @empty
                                    <tr>
                                        <td colspan="6" class="p-4">
                                            <div class="godslove-empty">
                                                <p class="godslove-empty-title">Todavia no hay ventas ligadas a este turno</p>
                                                <p class="godslove-empty-copy">Cuando registres ventas con caja abierta, apareceran aqui para el corte.</p>
                                            </div>
                                        </td>
                                    </tr>
                                @endforelse
                            </tbody>
                        </table>
                    </div>
                </div>

                <div class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm">
                    <div class="mb-4 flex flex-col gap-2 sm:flex-row sm:items-end sm:justify-between">
                        <div>
                            <h2 class="text-lg font-semibold text-slate-900">Detalle de gastos del turno</h2>
                            <p class="text-sm text-slate-500">Aqui ves exactamente en que se gasto y con que metodo salio el dinero.</p>
                        </div>
                        <span class="rounded-full bg-rose-50 px-3 py-1 text-xs font-medium text-rose-700">
                            {{ $this->gastosTurnoDetalles->count() }} gastos ligados a esta caja
                        </span>
                    </div>

                    <div class="overflow-x-auto">
                        <table class="min-w-full border-collapse text-left text-sm">
                            <thead>
                                <tr class="border-b border-slate-200 bg-slate-50 text-slate-600">
                                    <th class="p-3 font-medium">Fecha</th>
                                    <th class="p-3 font-medium">Descripcion</th>
                                    <th class="p-3 font-medium">Categoria</th>
                                    <th class="p-3 font-medium">Tipo</th>
                                    <th class="p-3 font-medium">Metodo</th>
                                    <th class="p-3 font-medium">Usuario</th>
                                    <th class="p-3 font-medium">Monto</th>
                                </tr>
                            </thead>
                            <tbody>
                                @forelse ($this->gastosTurnoDetalles as $gasto)
                                    <tr wire:key="gasto-turno-{{ $gasto->id }}" class="border-b border-slate-100">
                                        <td class="p-3 text-slate-700">{{ $gasto->created_at?->format('d/m/Y H:i') ?? 'Sin fecha' }}</td>
                                        <td class="p-3 font-medium text-slate-900">{{ $gasto->descripcion }}</td>
                                        <td class="p-3 text-slate-700">{{ $gasto->categoria?->nombre ?? 'Sin categoria' }}</td>
                                        <td class="p-3 text-slate-700 capitalize">{{ $gasto->tipo }}</td>
                                        <td class="p-3 text-slate-700 capitalize">{{ $gasto->metodo_pago }}</td>
                                        <td class="p-3 text-slate-700">{{ $gasto->user?->name ?? 'Sin usuario' }}</td>
                                        <td class="p-3 font-medium text-rose-700">${{ number_format((float) $gasto->monto, 2) }}</td>
                                    </tr>
                                @empty
                                    <tr>
                                        <td colspan="7" class="p-4">
                                            <div class="godslove-empty">
                                                <p class="godslove-empty-title">Todavia no hay gastos ligados a este turno</p>
                                                <p class="godslove-empty-copy">Los egresos desde caja del dia se veran aqui para cuadrar efectivo.</p>
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
                        <h2 class="text-lg font-semibold text-slate-900">Cerrar caja</h2>
                        <p class="text-sm text-slate-500">Captura el efectivo contado y deja observaciones del turno.</p>
                    </div>

                    <form wire:submit="cerrarCaja" class="space-y-4">
                        <div>
                            <label class="mb-1 block text-sm font-medium text-slate-700">Monto real contado</label>
                            <input
                                type="number"
                                step="0.01"
                                wire:model="monto_real"
                                class="w-full rounded-xl border-slate-300 text-sm"
                                placeholder="0.00"
                            >

                            @error('monto_real')
                                <p class="mt-1 text-sm text-red-600">{{ $message }}</p>
                            @enderror
                        </div>

                        <div>
                            <label class="mb-1 block text-sm font-medium text-slate-700">Observaciones</label>
                            <textarea
                                wire:model="observaciones"
                                rows="4"
                                class="w-full rounded-xl border-slate-300 text-sm"
                                placeholder="Notas del corte, faltantes o sobrantes."
                            ></textarea>

                            @error('observaciones')
                                <p class="mt-1 text-sm text-red-600">{{ $message }}</p>
                            @enderror
                        </div>

                        <div class="rounded-xl bg-slate-50 p-4">
                            <div class="flex items-center justify-between text-sm text-slate-600">
                                <span>Monto esperado</span>
                                <span>${{ number_format($this->montoEsperado, 2) }}</span>
                            </div>

                            <div class="mt-2 flex items-center justify-between text-sm text-slate-600">
                                <span>Diferencia estimada</span>
                                <span>${{ number_format((float) $monto_real - $this->montoEsperado, 2) }}</span>
                            </div>
                        </div>

                        <button
                            type="submit"
                            class="inline-flex items-center justify-center rounded-xl bg-emerald-600 px-4 py-2 text-sm font-medium text-white transition hover:bg-emerald-700"
                        >
                            Cerrar caja
                        </button>
                    </form>
                </div>

                <div class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm">
                    <div class="mb-4">
                        <h2 class="text-lg font-semibold text-slate-900">Analisis financiero separado</h2>
                        <p class="text-sm text-slate-500">El control semanal y mensual ya vive en una vista distinta para que caja se quede enfocada al turno.</p>
                    </div>

                    <a
                        href="{{ route('finanzas.cierres') }}"
                        wire:navigate
                        class="inline-flex items-center rounded-xl bg-slate-900 px-4 py-2 text-sm font-medium text-white transition hover:bg-slate-800"
                    >
                        Ir a Finanzas y cierres
                    </a>
                </div>
            </div>
        </div>
    @endif

    <div class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm">
        <div class="mb-4">
            <h2 class="text-lg font-semibold text-slate-900">Historial de cortes</h2>
            <p class="text-sm text-slate-500">Ultimos 10 cortes registrados.</p>
        </div>

        <div class="overflow-x-auto">
            <table class="min-w-full border-collapse text-left text-sm">
                <thead>
                    <tr class="border-b border-slate-200 bg-slate-50 text-slate-600">
                        <th class="p-3 font-medium">Fecha apertura</th>
                        <th class="p-3 font-medium">Fecha cierre</th>
                        <th class="p-3 font-medium">Usuario</th>
                        <th class="p-3 font-medium">Monto inicial</th>
                        <th class="p-3 font-medium">Ventas efectivo</th>
                        <th class="p-3 font-medium">Gastos</th>
                        <th class="p-3 font-medium">Monto esperado</th>
                        <th class="p-3 font-medium">Monto real</th>
                        <th class="p-3 font-medium">Diferencia</th>
                        <th class="p-3 font-medium">Estado</th>
                    </tr>
                </thead>

                <tbody>
                    @forelse ($this->historialCortes as $corte)
                        <tr wire:key="historial-corte-{{ $corte->id }}" class="border-b border-slate-100">
                            <td class="p-3 text-slate-700">
                                {{ $corte->fecha_apertura ? \Illuminate\Support\Carbon::parse($corte->fecha_apertura)->format('d/m/Y H:i') : 'Sin apertura' }}
                            </td>
                            <td class="p-3 text-slate-700">
                                {{ $corte->fecha_cierre ? \Illuminate\Support\Carbon::parse($corte->fecha_cierre)->format('d/m/Y H:i') : 'Abierta' }}
                            </td>
                            <td class="p-3 text-slate-700">{{ $corte->user?->name ?? 'Sin usuario' }}</td>
                            <td class="p-3 text-slate-700">${{ number_format((float) $corte->monto_inicial, 2) }}</td>
                            <td class="p-3 text-slate-700">${{ number_format((float) $corte->ventas_efectivo, 2) }}</td>
                            <td class="p-3 text-slate-700">${{ number_format((float) $corte->gastos_turno, 2) }}</td>
                            <td class="p-3 text-slate-700">${{ number_format((float) $corte->monto_esperado, 2) }}</td>
                            <td class="p-3 text-slate-700">${{ number_format((float) $corte->monto_real, 2) }}</td>
                            <td class="p-3 font-medium {{ (float) $corte->diferencia < 0 ? 'text-red-600' : 'text-emerald-700' }}">
                                ${{ number_format((float) $corte->diferencia, 2) }}
                            </td>
                            <td class="p-3">
                                <span class="rounded-full px-2.5 py-1 text-xs font-medium {{ $corte->estado === 'abierto' ? 'bg-amber-100 text-amber-700' : 'bg-slate-200 text-slate-700' }}">
                                    {{ $corte->estado }}
                                </span>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="10" class="p-4">
                                <div class="godslove-empty">
                                    <p class="godslove-empty-title">Todavia no hay cortes registrados</p>
                                    <p class="godslove-empty-copy">Abre y cierra caja para construir el historial diario.</p>
                                </div>
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>
</div>

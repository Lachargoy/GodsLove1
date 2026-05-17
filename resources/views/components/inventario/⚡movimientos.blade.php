<?php

use App\Models\Insumo;
use App\Models\MovimientoInventario;
use App\Services\InventoryEntryService;
use App\Services\InventarioService;
use Livewire\Component;

new class extends Component
{
    public string $insumo_id = '';

    public string $tipo = 'entrada';

    public string $cantidad = '';

    public string $costo_unitario = '';

    public string $motivo = '';

    public string $search = '';

    public function getInsumosProperty()
    {
        return Insumo::query()
            ->with(['categoria', 'inventoryItem'])
            ->where('activo', true)
            ->orderBy('nombre')
            ->get();
    }

    public function getInsumoSeleccionadoProperty(): ?Insumo
    {
        if ($this->insumo_id === '') {
            return null;
        }

        return Insumo::query()
            ->with(['categoria', 'inventoryItem'])
            ->find($this->insumo_id);
    }

    public function getTipoSeleccionadoProperty(): array
    {
        return [
            'entrada' => [
                'label' => 'Compra',
                'description' => 'Suma stock y recalcula costo promedio cuando el insumo esta ligado a inventario.',
            ],
            'salida' => [
                'label' => 'Salida',
                'description' => 'Resta inventario por uso operativo, ajuste manual o venta no automatizada.',
            ],
            'merma' => [
                'label' => 'Merma',
                'description' => 'Resta inventario por perdida, derrame, caducidad o diferencia fisica.',
            ],
        ][$this->tipo];
    }

    public function getMovimientosProperty()
    {
        return MovimientoInventario::query()
            ->with(['insumo', 'user'])
            ->when($this->search !== '', function ($query) {
                $search = '%'.trim($this->search).'%';

                $query->where(function ($subQuery) use ($search) {
                    $subQuery->where('motivo', 'like', $search)
                        ->orWhereHas('insumo', fn ($insumos) => $insumos->where('nombre', 'like', $search));
                });
            })
            ->latest('fecha_movimiento')
            ->limit(30)
            ->get();
    }

    public function getInventarioBajoProperty()
    {
        return Insumo::query()
            ->where('activo', true)
            ->whereColumn('cantidad_actual', '<=', 'cantidad_minima')
            ->orderBy('nombre')
            ->limit(6)
            ->get();
    }

    public function getTotalInsumosActivosProperty(): int
    {
        return Insumo::query()
            ->where('activo', true)
            ->count();
    }

    public function getTotalInventarioBajoProperty(): int
    {
        return $this->inventarioBajo->count();
    }

    public function getTotalMovimientosProperty(): int
    {
        return MovimientoInventario::query()->count();
    }

    public function getValorAproximadoInventarioProperty(): float
    {
        return (float) Insumo::query()
            ->where('activo', true)
            ->get()
            ->sum(fn (Insumo $insumo) => (float) $insumo->cantidad_actual * (float) $insumo->costo_unitario);
    }

    public function limpiarFormulario(): void
    {
        $this->reset([
            'insumo_id',
            'tipo',
            'cantidad',
            'costo_unitario',
            'motivo',
        ]);
    }

    public function registrarMovimiento(
        InventarioService $inventarioService,
        InventoryEntryService $inventoryEntryService,
    ): void {
        $validated = $this->validate([
            'insumo_id' => ['required', 'exists:insumos,id'],
            'tipo' => ['required', 'in:entrada,salida,merma'],
            'cantidad' => ['required', 'numeric', 'min:0.001'],
            'costo_unitario' => ['nullable', 'numeric', 'min:0'],
            'motivo' => ['nullable', 'string', 'max:500'],
        ]);

        $insumo = Insumo::query()->findOrFail($validated['insumo_id']);

        try {
            match ($validated['tipo']) {
                'entrada' => $insumo->inventory_item_id
                    ? $inventoryEntryService->recordPurchase(
                        inventoryItem: $insumo->inventoryItem()->firstOrFail(),
                        quantity: (float) $validated['cantidad'],
                        unitCost: (float) ($validated['costo_unitario'] ?: 0),
                        userId: auth()->id(),
                        notes: $validated['motivo'] ?: null,
                    )
                    : $inventarioService->registrarEntrada(
                        insumo: $insumo,
                        cantidad: (float) $validated['cantidad'],
                        costoUnitario: (float) ($validated['costo_unitario'] ?: 0),
                        userId: auth()->id(),
                        motivo: $validated['motivo'] ?: null,
                    ),
                'salida' => $inventarioService->registrarSalida(
                    insumo: $insumo,
                    cantidad: (float) $validated['cantidad'],
                    userId: auth()->id(),
                    motivo: $validated['motivo'] ?: null,
                ),
                'merma' => $inventarioService->registrarMerma(
                    insumo: $insumo,
                    cantidad: (float) $validated['cantidad'],
                    userId: auth()->id(),
                    motivo: $validated['motivo'] ?: null,
                ),
            };
        } catch (\RuntimeException $exception) {
            $this->addError('cantidad', $exception->getMessage());

            return;
        }

        $this->reset([
            'cantidad',
            'costo_unitario',
            'motivo',
        ]);

        session()->flash('success', 'Movimiento registrado correctamente.');
    }
};
?>

<div x-data="{ formOpen: true, alertsOpen: false, metricsOpen: true }" class="space-y-5">
    <header class="flex flex-col gap-3 sm:flex-row sm:items-end sm:justify-between">
        <div>
            <p class="text-xs font-semibold uppercase tracking-[0.18em] text-emerald-700">Inventario</p>
            <h1 class="mt-1 text-2xl font-semibold tracking-tight text-slate-950">Movimientos de inventario</h1>
            <p class="mt-1 max-w-2xl text-sm text-slate-500">
                Registra compras, salidas y mermas con control de costo promedio e historial operativo.
            </p>
        </div>

        <button type="button" @click="metricsOpen = !metricsOpen" class="inline-flex items-center gap-2 rounded-lg border border-slate-200 bg-white px-3 py-2 text-sm font-medium text-slate-600 transition hover:bg-slate-50">
            Resumen
            <span x-text="metricsOpen ? 'Ocultar' : 'Ver'" class="text-xs text-slate-400"></span>
        </button>
    </header>

    <div x-show="metricsOpen" class="flex flex-wrap items-center gap-x-4 gap-y-2 rounded-xl border border-slate-200 bg-white px-4 py-3 text-sm text-slate-500">
            <span><strong class="font-semibold text-slate-900">{{ $this->totalInsumosActivos }}</strong> insumos activos</span>
            <span><strong class="font-semibold text-slate-900">{{ $this->totalMovimientos }}</strong> movimientos</span>
            <span><strong class="font-semibold text-slate-900">${{ number_format($this->valorAproximadoInventario, 2) }}</strong> inventario estimado</span>
    </div>

    <section class="overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-sm">
        <div class="grid min-h-[680px] xl:grid-cols-[340px_minmax(0,1fr)]">
            <aside class="border-b border-slate-200 bg-slate-50/60 xl:border-b-0 xl:border-r">
                <div class="border-b border-slate-200 px-5 py-4">
                    <button type="button" @click="formOpen = !formOpen" class="flex w-full items-start justify-between gap-3 text-left">
                        <span>
                            <span class="block text-base font-semibold text-slate-950">Registrar movimiento</span>
                            <span class="mt-1 block text-sm text-slate-500">Panel rapido para actualizar stock.</span>
                        </span>
                        <span x-text="formOpen ? 'Ocultar' : 'Abrir'" class="mt-0.5 whitespace-nowrap text-xs font-semibold text-emerald-700"></span>
                    </button>
                </div>

                <form x-show="formOpen" wire:submit="registrarMovimiento" class="space-y-4 px-5 py-5">
                    <div>
                        <label class="mb-1.5 block text-sm font-medium text-slate-700">Insumo</label>
                        <select wire:model.live="insumo_id" class="w-full rounded-lg border-slate-300 bg-white text-sm text-slate-900 shadow-none focus:border-emerald-500 focus:ring-emerald-500">
                            <option value="">Selecciona un insumo</option>

                            @foreach ($this->insumos as $insumo)
                                <option value="{{ $insumo->id }}">
                                    {{ $insumo->nombre }} | {{ number_format((float) $insumo->cantidad_actual, 3) }} {{ $insumo->unidad_medida }}
                                </option>
                            @endforeach
                        </select>

                        @error('insumo_id')
                            <p class="mt-1 text-sm text-red-600">{{ $message }}</p>
                        @enderror
                    </div>

                    @if ($this->insumoSeleccionado)
                        <dl class="grid grid-cols-3 gap-3 border-y border-slate-200 py-3 text-xs">
                            <div class="min-w-0">
                                <dt class="text-slate-500">Stock</dt>
                                <dd class="mt-1 truncate font-semibold text-slate-900">
                                    {{ number_format((float) $this->insumoSeleccionado->cantidad_actual, 3) }} {{ $this->insumoSeleccionado->unidad_medida }}
                                </dd>
                            </div>
                            <div class="min-w-0">
                                <dt class="text-slate-500">Costo</dt>
                                <dd class="mt-1 truncate font-semibold text-slate-900">${{ number_format((float) $this->insumoSeleccionado->costo_unitario, 4) }}</dd>
                            </div>
                            <div class="min-w-0">
                                <dt class="text-slate-500">Categoria</dt>
                                <dd class="mt-1 truncate font-semibold text-slate-900">{{ $this->insumoSeleccionado->categoria?->nombre ?? 'Sin categoria' }}</dd>
                            </div>
                        </dl>
                    @endif

                    <div>
                        <label class="mb-1.5 block text-sm font-medium text-slate-700">Tipo</label>
                        <div class="grid grid-cols-3 overflow-hidden rounded-lg border border-slate-300 bg-white p-0.5">
                            @foreach ([
                                'entrada' => 'Compra',
                                'salida' => 'Salida',
                                'merma' => 'Merma',
                            ] as $value => $label)
                                <label class="cursor-pointer">
                                    <input type="radio" wire:model.live="tipo" value="{{ $value }}" class="sr-only">
                                    <span class="block rounded-md px-2 py-2 text-center text-sm font-medium transition {{ $tipo === $value ? 'bg-emerald-600 text-white' : 'text-slate-600 hover:bg-slate-100 hover:text-slate-900' }}">
                                        {{ $label }}
                                    </span>
                                </label>
                            @endforeach
                        </div>
                        <p class="mt-2 text-xs leading-5 text-slate-500">{{ $this->tipoSeleccionado['description'] }}</p>

                        @error('tipo')
                            <p class="mt-1 text-sm text-red-600">{{ $message }}</p>
                        @enderror
                    </div>

                    <div class="grid grid-cols-2 gap-3">
                        <div>
                            <label class="mb-1.5 block text-sm font-medium text-slate-700">Cantidad</label>
                            <input
                                type="number"
                                step="0.001"
                                wire:model="cantidad"
                                class="w-full rounded-lg border-slate-300 bg-white text-sm text-slate-900 shadow-none focus:border-emerald-500 focus:ring-emerald-500"
                                placeholder="10.000"
                            >

                            @error('cantidad')
                                <p class="mt-1 text-sm text-red-600">{{ $message }}</p>
                            @enderror
                        </div>

                        <div>
                            <label class="mb-1.5 block text-sm font-medium text-slate-700">Costo</label>
                            <input
                                type="number"
                                step="0.01"
                                wire:model="costo_unitario"
                                class="w-full rounded-lg border-slate-300 bg-white text-sm text-slate-900 shadow-none disabled:bg-slate-100 disabled:text-slate-400 focus:border-emerald-500 focus:ring-emerald-500"
                                placeholder="{{ $tipo === 'entrada' ? '0.00' : 'N/A' }}"
                                @disabled($tipo !== 'entrada')
                            >

                            @error('costo_unitario')
                                <p class="mt-1 text-sm text-red-600">{{ $message }}</p>
                            @enderror
                        </div>
                    </div>

                    <div>
                        <label class="mb-1.5 block text-sm font-medium text-slate-700">Motivo</label>
                        <textarea
                            wire:model="motivo"
                            class="w-full resize-none rounded-lg border-slate-300 bg-white text-sm text-slate-900 shadow-none focus:border-emerald-500 focus:ring-emerald-500"
                            rows="3"
                            placeholder="Ej. Compra semanal, ajuste, merma..."
                        ></textarea>

                        @error('motivo')
                            <p class="mt-1 text-sm text-red-600">{{ $message }}</p>
                        @enderror
                    </div>

                    <div class="flex items-center gap-2 pt-1">
                        <button type="submit" class="inline-flex flex-1 items-center justify-center rounded-lg bg-emerald-600 px-4 py-2.5 text-sm font-semibold text-white transition hover:bg-emerald-700">
                            Registrar movimiento
                        </button>
                        <button type="button" wire:click="limpiarFormulario" class="inline-flex items-center justify-center rounded-lg border border-slate-300 bg-white px-4 py-2.5 text-sm font-medium text-slate-700 transition hover:bg-slate-50">
                            Limpiar
                        </button>
                    </div>
                </form>

                <div class="border-t border-slate-200 px-5 py-4">
                    <button type="button" @click="alertsOpen = !alertsOpen" class="flex w-full items-center justify-between gap-3 text-left">
                        <h3 class="text-sm font-semibold text-slate-900">Inventario bajo</h3>
                        <span class="whitespace-nowrap rounded-full bg-amber-100 px-2 py-0.5 text-xs font-semibold text-amber-800">{{ $this->totalInventarioBajo }}</span>
                    </button>

                    <div x-show="alertsOpen" class="mt-3 space-y-2">
                        @forelse ($this->inventarioBajo as $insumo)
                            <div wire:key="bajo-{{ $insumo->id }}" class="flex items-center justify-between gap-3 text-sm">
                                <span class="min-w-0 truncate text-slate-700">{{ $insumo->nombre }}</span>
                                <span class="whitespace-nowrap text-xs font-medium text-amber-700">
                                    {{ number_format((float) $insumo->cantidad_actual, 3) }} {{ $insumo->unidad_medida }}
                                </span>
                            </div>
                        @empty
                            <p class="text-sm text-slate-500">Sin alertas por ahora.</p>
                        @endforelse
                    </div>
                </div>
            </aside>

            <div class="min-w-0">
                <div class="border-b border-slate-200 px-5 py-4">
                    <div class="flex flex-col gap-3 lg:flex-row lg:items-end lg:justify-between">
                        <div>
                            <h2 class="text-base font-semibold text-slate-950">Historial reciente</h2>
                            <p class="mt-1 text-sm text-slate-500">Ultimos 30 movimientos visibles para auditoria rapida.</p>
                        </div>

                        <div class="flex flex-col gap-2 sm:flex-row sm:items-center">
                            <label class="sr-only" for="movimientos-search">Buscar en historial</label>
                            <input
                                id="movimientos-search"
                                type="text"
                                wire:model.live="search"
                                class="w-full rounded-lg border-slate-300 bg-white text-sm text-slate-900 shadow-none sm:w-72 focus:border-emerald-500 focus:ring-emerald-500"
                                placeholder="Buscar insumo o detalle"
                            >
                            <span class="whitespace-nowrap rounded-lg border border-slate-200 bg-slate-50 px-3 py-2 text-xs font-medium text-slate-500">
                                {{ now()->format('d/m/Y') }}
                            </span>
                        </div>
                    </div>
                </div>

                <div class="max-h-[620px] overflow-auto">
                    <table class="min-w-[980px] table-fixed text-left text-sm">
                        <colgroup>
                            <col class="w-[132px]">
                            <col class="w-[210px]">
                            <col class="w-[116px]">
                            <col class="w-[110px]">
                            <col class="w-[110px]">
                            <col>
                            <col class="w-[130px]">
                        </colgroup>
                        <thead class="sticky top-0 z-10 border-b border-slate-200 bg-white text-xs font-semibold uppercase tracking-[0.08em] text-slate-500">
                            <tr>
                                <th class="whitespace-nowrap px-4 py-3">Fecha</th>
                                <th class="px-4 py-3">Insumo</th>
                                <th class="whitespace-nowrap px-4 py-3">Movimiento</th>
                                <th class="whitespace-nowrap px-4 py-3 text-right">Cantidad</th>
                                <th class="whitespace-nowrap px-4 py-3 text-right">Costo</th>
                                <th class="px-4 py-3">Detalle</th>
                                <th class="whitespace-nowrap px-4 py-3">Usuario</th>
                            </tr>
                        </thead>

                        <tbody class="divide-y divide-slate-100">
                            @forelse ($this->movimientos as $movimiento)
                                <tr wire:key="movimiento-{{ $movimiento->id }}" class="h-14 align-middle hover:bg-slate-50/80">
                                    <td class="whitespace-nowrap px-4 py-3 text-slate-600">
                                        {{ optional($movimiento->fecha_movimiento)->format('d/m/Y H:i') }}
                                    </td>

                                    <td class="px-4 py-3 font-medium text-slate-900">
                                        <div class="line-clamp-2 leading-5">{{ $movimiento->insumo?->nombre }}</div>
                                    </td>

                                    <td class="whitespace-nowrap px-4 py-3">
                                        @php
                                            $tipo = $movimiento->tipo;
                                            $badgeClasses = match ($tipo) {
                                                'entrada' => 'bg-emerald-50 text-emerald-700 ring-emerald-200',
                                                'salida', 'venta' => 'bg-amber-50 text-amber-700 ring-amber-200',
                                                'merma' => 'bg-rose-50 text-rose-700 ring-rose-200',
                                                default => 'bg-slate-50 text-slate-700 ring-slate-200',
                                            };
                                            $tipoLabel = match ($tipo) {
                                                'entrada' => 'Compra',
                                                'salida' => 'Salida',
                                                'merma' => 'Merma',
                                                'venta' => 'Venta',
                                                default => ucfirst((string) $tipo),
                                            };
                                        @endphp

                                        <span class="inline-flex rounded-full px-2 py-0.5 text-xs font-semibold ring-1 {{ $badgeClasses }}">
                                            {{ $tipoLabel }}
                                        </span>
                                    </td>

                                    <td class="whitespace-nowrap px-4 py-3 text-right font-semibold tabular-nums {{ (float) $movimiento->cantidad < 0 ? 'text-rose-700' : 'text-emerald-700' }}">
                                        {{ number_format((float) $movimiento->cantidad, 3) }}
                                    </td>

                                    <td class="whitespace-nowrap px-4 py-3 text-right tabular-nums text-slate-700">
                                        ${{ number_format((float) $movimiento->costo_unitario, 2) }}
                                    </td>

                                    <td class="px-4 py-3 text-slate-600">
                                        <div class="line-clamp-2 leading-5">{{ $movimiento->motivo ?: 'Sin detalle' }}</div>
                                    </td>

                                    <td class="whitespace-nowrap px-4 py-3 text-slate-600">
                                        <span class="block truncate">{{ $movimiento->user?->name ?? 'Sistema' }}</span>
                                    </td>
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="7" class="px-4 py-6">
                                        <div class="godslove-empty">
                                            <p class="godslove-empty-title">No hay movimientos con esos filtros</p>
                                            <p class="godslove-empty-copy">Las entradas, salidas y ajustes apareceran aqui cuando los registres.</p>
                                        </div>
                                    </td>
                                </tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </section>
</div>

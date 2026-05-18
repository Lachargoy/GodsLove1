<?php

use App\Models\CategoriaInsumo;
use App\Models\InventoryItem;
use App\Models\Insumo;
use App\Models\Unit;
use App\Services\InventarioService;
use Livewire\Component;

new class extends Component
{
    public string $search = '';
    public string $categoria_insumo_id = '';
    public string $estadoFilter = 'activos';
    public string $nombre = '';
    public string $tipo_uso = 'receta';
    public string $unidad_medida = 'pieza';
    public string $cantidad_actual = '';
    public string $cantidad_minima = '';
    public string $costo_unitario = '';
    public string $editing_insumo_id = '';
    public string $editing_field = '';
    public string $editing_value = '';

    public function getCategoriasProperty()
    {
        return CategoriaInsumo::query()
            ->where('activo', true)
            ->orderBy('nombre')
            ->get();
    }

    public function insumos()
    {
        return Insumo::query()
            ->with(['categoria', 'inventoryItem'])
            ->when($this->search !== '', function ($query) {
                $query->where('nombre', 'like', '%'.trim($this->search).'%');
            })
            ->when($this->categoria_insumo_id !== '', function ($query) {
                $query->where('categoria_insumo_id', $this->categoria_insumo_id);
            })
            ->when($this->estadoFilter === 'activos', function ($query) {
                $query->where('activo', true);
            })
            ->when($this->estadoFilter === 'inactivos', function ($query) {
                $query->where('activo', false);
            })
            ->orderByDesc('created_at')
            ->get();
    }

    public function getTotalInsumosProperty(): int
    {
        return Insumo::query()->count();
    }

    public function getInsumosActivosProperty(): int
    {
        return Insumo::query()
            ->where('activo', true)
            ->count();
    }

    public function getInsumosBajosProperty(): int
    {
        return Insumo::query()
            ->where('activo', true)
            ->whereColumn('cantidad_actual', '<=', 'cantidad_minima')
            ->count();
    }

    public function getValorTotalInventarioProperty(): float
    {
        return (float) Insumo::query()
            ->where('activo', true)
            ->get()
            ->sum(fn (Insumo $insumo) => (float) $insumo->cantidad_actual * (float) $insumo->costo_unitario);
    }

    public function filtrarEstado(string $estado): void
    {
        if (! in_array($estado, ['activos', 'todos', 'inactivos'], true)) {
            return;
        }

        $this->estadoFilter = $estado;
    }

    public function guardar(): void
    {
        $validated = $this->validate([
            'categoria_insumo_id' => ['nullable', 'exists:categoria_insumos,id'],
            'nombre' => ['required', 'string', 'max:255'],
            'tipo_uso' => ['required', 'in:receta,producto_unico'],
            'unidad_medida' => ['required', 'string', 'max:50'],
            'cantidad_actual' => ['required', 'numeric', 'min:0'],
            'cantidad_minima' => ['required', 'numeric', 'min:0'],
            'costo_unitario' => ['required', 'numeric', 'min:0'],
        ]);

        $insumo = Insumo::query()->create([
            'categoria_insumo_id' => $validated['categoria_insumo_id'] ?: null,
            'nombre' => trim($validated['nombre']),
            'unidad_medida' => trim($validated['unidad_medida']),
            'cantidad_actual' => $validated['cantidad_actual'],
            'cantidad_minima' => $validated['cantidad_minima'],
            'costo_unitario' => $validated['costo_unitario'],
            'activo' => true,
        ]);

        $inventoryItem = $this->crearInventoryItemParaInsumo($insumo);

        $insumo->update([
            'inventory_item_id' => $inventoryItem->id,
        ]);

        $this->reset([
            'categoria_insumo_id',
            'nombre',
            'tipo_uso',
            'cantidad_actual',
            'cantidad_minima',
            'costo_unitario',
        ]);

        $this->tipo_uso = 'receta';
        $this->unidad_medida = 'pieza';

        session()->flash('success', 'Insumo creado correctamente.');
    }

    private function crearInventoryItemParaInsumo(Insumo $insumo): InventoryItem
    {
        $unit = $this->obtenerUnidad($insumo->unidad_medida);

        return InventoryItem::query()->updateOrCreate(
            [
                'legacy_table' => 'insumos',
                'legacy_id' => $insumo->id,
            ],
            [
                'unit_id' => $unit->id,
                'name' => $insumo->nombre,
                'current_stock' => (float) $insumo->cantidad_actual,
                'minimum_stock' => (float) $insumo->cantidad_minima,
                'average_cost' => (float) $insumo->costo_unitario,
                'allows_decimals' => (bool) $unit->allows_decimals,
                'is_sellable' => $this->tipo_uso === 'producto_unico',
                'is_consumable' => true,
                'is_active' => (bool) $insumo->activo,
            ],
        );
    }

    private function obtenerUnidad(string $unidadMedida): Unit
    {
        $nombreUnidad = match (mb_strtolower(trim($unidadMedida))) {
            'kg', 'kilo', 'kilogramo' => 'kilogramo',
            'g', 'gr', 'gramo' => 'gramo',
            'l', 'lt', 'litro' => 'litro',
            'ml', 'mililitro' => 'mililitro',
            'pz', 'pieza' => 'pieza',
            default => mb_strtolower(trim($unidadMedida)),
        };

        $abreviatura = match ($nombreUnidad) {
            'kilogramo' => 'kg',
            'gramo' => 'g',
            'litro' => 'L',
            'mililitro' => 'ml',
            'pieza' => 'pz',
            default => $nombreUnidad,
        };

        return Unit::query()->firstOrCreate(
            ['name' => $nombreUnidad],
            [
                'abbreviation' => $abreviatura,
                'allows_decimals' => in_array($nombreUnidad, ['litro', 'mililitro', 'kilogramo', 'gramo'], true),
            ],
        );
    }

    public function toggleActivo(int $insumoId): void
    {
        $insumo = Insumo::query()->findOrFail($insumoId);

        $insumo->update([
            'activo' => ! $insumo->activo,
        ]);

        session()->flash(
            'success',
            $insumo->fresh()->activo
                ? 'Insumo activado correctamente.'
                : 'Insumo desactivado correctamente.',
        );
    }

    public function editarInline(int $insumoId, string $field): void
    {
        abort_unless(in_array($field, [
            'categoria_insumo_id',
            'nombre',
            'tipo_uso',
            'unidad_medida',
            'cantidad_actual',
            'cantidad_minima',
            'costo_unitario',
        ], true), 404);

        $insumo = Insumo::query()
            ->with('inventoryItem')
            ->findOrFail($insumoId);

        $this->editing_insumo_id = (string) $insumo->id;
        $this->editing_field = $field;
        $this->editing_value = match ($field) {
            'categoria_insumo_id' => (string) ($insumo->categoria_insumo_id ?? ''),
            'tipo_uso' => $insumo->inventoryItem?->is_sellable ? 'producto_unico' : 'receta',
            default => (string) $insumo->{$field},
        };
    }

    public function cancelarEdicionInline(): void
    {
        $this->reset([
            'editing_insumo_id',
            'editing_field',
            'editing_value',
        ]);
    }

    public function guardarEdicionInline(InventarioService $inventarioService): void
    {
        if ($this->editing_insumo_id === '' || $this->editing_field === '') {
            return;
        }

        $rules = [
            'categoria_insumo_id' => ['nullable', 'exists:categoria_insumos,id'],
            'nombre' => ['required', 'string', 'max:255'],
            'tipo_uso' => ['required', 'in:receta,producto_unico'],
            'unidad_medida' => ['required', 'string', 'max:50'],
            'cantidad_actual' => ['required', 'numeric', 'min:0'],
            'cantidad_minima' => ['required', 'numeric', 'min:0'],
            'costo_unitario' => ['required', 'numeric', 'min:0'],
        ];

        $this->validate([
            'editing_value' => $rules[$this->editing_field],
        ]);

        $insumo = Insumo::query()
            ->with('inventoryItem')
            ->findOrFail($this->editing_insumo_id);

        match ($this->editing_field) {
            'categoria_insumo_id' => $insumo->update([
                'categoria_insumo_id' => $this->editing_value !== '' ? $this->editing_value : null,
            ]),
            'tipo_uso' => $this->obtenerInventoryItemParaInsumo($insumo)->update([
                'is_sellable' => $this->editing_value === 'producto_unico',
            ]),
            'unidad_medida' => $this->actualizarUnidadInline($insumo),
            'cantidad_actual' => $this->ajustarCantidadActualInline($insumo, $inventarioService),
            'cantidad_minima' => $this->actualizarInsumoEInventario($insumo, 'cantidad_minima', 'minimum_stock'),
            'costo_unitario' => $this->actualizarInsumoEInventario($insumo, 'costo_unitario', 'average_cost'),
            default => $this->actualizarNombreInline($insumo),
        };

        $this->cancelarEdicionInline();
        session()->flash('success', 'Campo actualizado correctamente.');
    }

    private function actualizarNombreInline(Insumo $insumo): void
    {
        $nombre = trim($this->editing_value);

        $insumo->update([
            'nombre' => $nombre,
        ]);

        $this->obtenerInventoryItemParaInsumo($insumo)->update([
            'name' => $nombre,
        ]);
    }

    private function actualizarUnidadInline(Insumo $insumo): void
    {
        $unidadMedida = trim($this->editing_value);
        $unit = $this->obtenerUnidad($unidadMedida);

        $insumo->update([
            'unidad_medida' => $unidadMedida,
        ]);

        $this->obtenerInventoryItemParaInsumo($insumo)->update([
            'unit_id' => $unit->id,
            'allows_decimals' => (bool) $unit->allows_decimals,
        ]);
    }

    private function actualizarInsumoEInventario(Insumo $insumo, string $insumoField, string $inventoryField): void
    {
        $value = (float) $this->editing_value;

        $insumo->update([
            $insumoField => $value,
        ]);

        $this->obtenerInventoryItemParaInsumo($insumo)->update([
            $inventoryField => $value,
        ]);
    }

    private function ajustarCantidadActualInline(Insumo $insumo, InventarioService $inventarioService): void
    {
        $cantidadObjetivo = round((float) $this->editing_value, 3);
        $cantidadActual = round((float) $insumo->cantidad_actual, 3);
        $diferencia = round($cantidadObjetivo - $cantidadActual, 3);

        if ($diferencia === 0.0) {
            return;
        }

        $inventoryItem = $this->obtenerInventoryItemParaInsumo($insumo);
        $costoPromedio = (float) ($inventoryItem->average_cost ?: $insumo->costo_unitario ?: 0);
        $tipo = $diferencia > 0 ? 'entrada' : 'salida';

        $inventarioService->registrarMovimiento(
            insumo: $insumo,
            tipo: $tipo,
            cantidad: abs($diferencia),
            costoUnitario: $costoPromedio,
            userId: auth()->id(),
            motivo: 'Ajuste inline de inventario',
        );

        $insumo->refresh();

        $inventoryItem->update([
            'current_stock' => (float) $insumo->cantidad_actual,
            'average_cost' => $costoPromedio,
        ]);
    }

    private function obtenerInventoryItemParaInsumo(Insumo $insumo): InventoryItem
    {
        if ($insumo->inventoryItem instanceof InventoryItem) {
            return $insumo->inventoryItem;
        }

        $unit = $this->obtenerUnidad($insumo->unidad_medida);

        $inventoryItem = InventoryItem::query()->updateOrCreate(
            [
                'legacy_table' => 'insumos',
                'legacy_id' => $insumo->id,
            ],
            [
                'unit_id' => $unit->id,
                'name' => $insumo->nombre,
                'current_stock' => (float) $insumo->cantidad_actual,
                'minimum_stock' => (float) $insumo->cantidad_minima,
                'average_cost' => (float) $insumo->costo_unitario,
                'allows_decimals' => (bool) $unit->allows_decimals,
                'is_sellable' => false,
                'is_consumable' => true,
                'is_active' => (bool) $insumo->activo,
            ],
        );

        $insumo->update([
            'inventory_item_id' => $inventoryItem->id,
        ]);

        $insumo->setRelation('inventoryItem', $inventoryItem);

        return $inventoryItem;
    }

    public function limpiarFiltros(): void
    {
        $this->reset([
            'search',
            'categoria_insumo_id',
            'estadoFilter',
        ]);

        $this->estadoFilter = 'activos';
    }
};
?>

<div class="space-y-6">
    <div class="app-hero flex flex-col gap-2 sm:flex-row sm:items-end sm:justify-between">
        <div>
            <h1 class="app-page-title">Insumos</h1>
            <p class="app-page-copy">
                Administra inventario base, cantidades mínimas y costos unitarios.
            </p>
        </div>

        <a href="{{ route('categorias.index') }}" wire:navigate class="app-secondary-button">
            Administrar categorias
        </a>
    </div>

    <div class="grid gap-4 md:grid-cols-2 xl:grid-cols-4">
        <div class="app-card">
            <p class="text-sm text-slate-500">Total insumos</p>
            <p class="mt-2 text-3xl font-semibold text-slate-900">{{ $this->totalInsumos }}</p>
        </div>

        <div class="app-stat-card border-emerald-200 bg-emerald-50/90">
            <p class="text-sm text-emerald-700">Activos</p>
            <p class="mt-2 text-3xl font-semibold text-emerald-900">{{ $this->insumosActivos }}</p>
        </div>

        <div class="app-stat-card border-amber-200 bg-amber-50/90">
            <p class="text-sm text-amber-700">Inventario bajo</p>
            <p class="mt-2 text-3xl font-semibold text-amber-900">{{ $this->insumosBajos }}</p>
        </div>

        <div class="app-stat-card border-sky-200 bg-sky-50/90">
            <p class="text-sm text-sky-700">Valor inventario</p>
            <p class="mt-2 text-3xl font-semibold text-sky-900">
                ${{ number_format($this->valorTotalInventario, 2) }}
            </p>
        </div>
    </div>

    <div class="grid gap-6 xl:grid-cols-[1.1fr_0.9fr]">
        <div class="app-card">
            <div class="mb-4">
                <h2 class="text-lg font-semibold text-slate-900">Nuevo insumo</h2>
                <p class="text-sm text-slate-500">Registra materias primas, desechables y otros insumos base.</p>
            </div>

            <form wire:submit="guardar" class="grid gap-4 md:grid-cols-2">
                <div>
                    <label class="mb-1 block text-sm font-medium text-slate-700">Categoría</label>
                    <select wire:model="categoria_insumo_id" class="w-full rounded-xl border-slate-300 text-sm">
                        <option value="">Sin categoría</option>

                        @foreach ($this->categorias as $categoria)
                            <option value="{{ $categoria->id }}">
                                {{ $categoria->nombre }}
                            </option>
                        @endforeach
                    </select>

                    @error('categoria_insumo_id')
                        <p class="mt-1 text-sm text-red-600">{{ $message }}</p>
                    @enderror
                </div>

                <div>
                    <label class="mb-1 block text-sm font-medium text-slate-700">Nombre</label>
                    <input
                        type="text"
                        wire:model="nombre"
                        class="w-full rounded-xl border-slate-300 text-sm"
                        placeholder="Ej. Vasos grandes"
                    >

                    @error('nombre')
                        <p class="mt-1 text-sm text-red-600">{{ $message }}</p>
                    @enderror
                </div>

                <div class="md:col-span-2">
                    <label class="mb-2 block text-sm font-medium text-slate-700">Uso del insumo</label>

                    <div class="grid gap-3 md:grid-cols-2">
                        <label class="flex cursor-pointer items-start gap-3 rounded-2xl border border-slate-200 bg-slate-50 p-4 transition hover:border-slate-300">
                            <input
                                type="radio"
                                wire:model="tipo_uso"
                                value="receta"
                                class="mt-1 border-slate-300 text-slate-900"
                            >

                            <div>
                                <p class="text-sm font-medium text-slate-900">Para receta</p>
                                <p class="text-xs text-slate-500">Se usa como ingrediente o insumo base para otros productos.</p>
                            </div>
                        </label>

                        <label class="flex cursor-pointer items-start gap-3 rounded-2xl border border-slate-200 bg-slate-50 p-4 transition hover:border-slate-300">
                            <input
                                type="radio"
                                wire:model="tipo_uso"
                                value="producto_unico"
                                class="mt-1 border-slate-300 text-slate-900"
                            >

                            <div>
                                <p class="text-sm font-medium text-slate-900">Producto unico</p>
                                <p class="text-xs text-slate-500">Se registra en inventario como algo que tambien puede venderse directo.</p>
                            </div>
                        </label>
                    </div>

                    @error('tipo_uso')
                        <p class="mt-1 text-sm text-red-600">{{ $message }}</p>
                    @enderror
                </div>

                <div>
                    <label class="mb-1 block text-sm font-medium text-slate-700">Unidad de medida</label>
                    <select wire:model="unidad_medida" class="w-full rounded-xl border-slate-300 text-sm">
                        <option value="pieza">pieza</option>
                        <option value="litro">litro</option>
                        <option value="mililitro">mililitro</option>
                        <option value="kg">kg</option>
                        <option value="gramo">gramo</option>
                        <option value="paquete">paquete</option>
                        <option value="caja">caja</option>
                    </select>

                    @error('unidad_medida')
                        <p class="mt-1 text-sm text-red-600">{{ $message }}</p>
                    @enderror
                </div>

                <div>
                    <label class="mb-1 block text-sm font-medium text-slate-700">Cantidad actual</label>
                    <input
                        type="number"
                        step="0.001"
                        wire:model="cantidad_actual"
                        class="w-full rounded-xl border-slate-300 text-sm"
                        placeholder="100.000"
                    >

                    @error('cantidad_actual')
                        <p class="mt-1 text-sm text-red-600">{{ $message }}</p>
                    @enderror
                </div>

                <div>
                    <label class="mb-1 block text-sm font-medium text-slate-700">Cantidad mínima</label>
                    <input
                        type="number"
                        step="0.001"
                        wire:model="cantidad_minima"
                        class="w-full rounded-xl border-slate-300 text-sm"
                        placeholder="30.000"
                    >

                    @error('cantidad_minima')
                        <p class="mt-1 text-sm text-red-600">{{ $message }}</p>
                    @enderror
                </div>

                <div>
                    <label class="mb-1 block text-sm font-medium text-slate-700">Costo unitario</label>
                    <input
                        type="number"
                        step="0.01"
                        wire:model="costo_unitario"
                        class="w-full rounded-xl border-slate-300 text-sm"
                        placeholder="1.50"
                    >

                    @error('costo_unitario')
                        <p class="mt-1 text-sm text-red-600">{{ $message }}</p>
                    @enderror
                </div>

                <div class="md:col-span-2">
                    <button
                        type="submit"
                        class="app-primary-button"
                    >
                        Guardar insumo
                    </button>
                </div>
            </form>
        </div>

        <div class="app-card">
            <div class="mb-4">
                <h2 class="text-lg font-semibold text-slate-900">Filtros</h2>
                <p class="text-sm text-slate-500">Busca y segmenta insumos por nombre o categoría.</p>
            </div>

            <div class="space-y-4">
                <div>
                    <label class="mb-1 block text-sm font-medium text-slate-700">Buscar por nombre</label>
                    <input
                        type="text"
                        wire:model.live="search"
                        class="w-full rounded-xl border-slate-300 text-sm"
                        placeholder="Ej. Leche"
                    >
                </div>

                <div>
                    <label class="mb-1 block text-sm font-medium text-slate-700">Filtrar por categoría</label>
                    <select wire:model.live="categoria_insumo_id" class="w-full rounded-xl border-slate-300 text-sm">
                        <option value="">Todas las categorías</option>

                        @foreach ($this->categorias as $categoria)
                            <option value="{{ $categoria->id }}">
                                {{ $categoria->nombre }}
                            </option>
                        @endforeach
                    </select>
                </div>

                <div>
                    <label class="mb-1 block text-sm font-medium text-slate-700">Estado</label>
                    <select wire:change="filtrarEstado($event.target.value)" class="w-full rounded-xl border-slate-300 text-sm">
                        <option value="activos" @selected($estadoFilter === 'activos')>Solo activos</option>
                        <option value="todos" @selected($estadoFilter === 'todos')>Todos</option>
                        <option value="inactivos" @selected($estadoFilter === 'inactivos')>Solo inactivos</option>
                    </select>
                </div>

                <button
                    type="button"
                    wire:click="limpiarFiltros"
                    class="app-secondary-button"
                >
                    Limpiar filtros
                </button>
            </div>
        </div>
    </div>

    <div class="app-card">
        <div class="mb-4">
            <h2 class="text-lg font-semibold text-slate-900">Lista de insumos</h2>
            <p class="text-sm text-slate-500">Consulta cantidades, costos y señales de inventario bajo.</p>
        </div>

        <div class="app-table-wrap">
            <table class="app-table">
                <thead>
                    <tr class="border-b border-slate-200 bg-slate-50 text-slate-600">
                        <th class="p-3 font-medium">Insumo</th>
                        <th class="p-3 font-medium">Categoría</th>
                        <th class="p-3 font-medium">Uso</th>
                        <th class="p-3 font-medium">Unidad</th>
                        <th class="p-3 font-medium">Cantidad actual</th>
                        <th class="p-3 font-medium">Mínimo</th>
                        <th class="p-3 font-medium">Costo unitario</th>
                        <th class="p-3 font-medium">Valor inventario</th>
                        <th class="p-3 font-medium">Estado</th>
                        <th class="p-3 font-medium">Acciones</th>
                    </tr>
                </thead>

                <tbody>
                    @forelse ($this->insumos() as $insumo)
                        @php
                            $inventarioBajo = (float) $insumo->cantidad_actual <= (float) $insumo->cantidad_minima;
                            $valorInventario = (float) $insumo->cantidad_actual * (float) $insumo->costo_unitario;
                        @endphp

                        <tr wire:key="insumo-{{ $insumo->id }}" class="border-b border-slate-100 align-top">
                            <td class="p-3">
                                @if ($editing_insumo_id === (string) $insumo->id && $editing_field === 'nombre')
                                    <input type="text" wire:model.defer="editing_value" wire:keydown.enter="guardarEdicionInline" wire:keydown.escape="cancelarEdicionInline" class="w-full rounded-lg border-emerald-300 text-sm" autofocus>
                                    @error('editing_value')
                                        <p class="mt-1 text-xs text-red-600">{{ $message }}</p>
                                    @enderror
                                @else
                                    <button type="button" wire:click="editarInline({{ $insumo->id }}, 'nombre')" class="block max-w-[220px] truncate text-left font-medium text-slate-900 hover:text-emerald-700">
                                        {{ $insumo->nombre }}
                                    </button>
                                @endif
                            </td>

                            <td class="p-3 text-slate-700">
                                @if ($editing_insumo_id === (string) $insumo->id && $editing_field === 'categoria_insumo_id')
                                    <select wire:model.defer="editing_value" wire:change="guardarEdicionInline" wire:keydown.escape="cancelarEdicionInline" class="w-full rounded-lg border-emerald-300 text-sm">
                                        <option value="">Sin categoria</option>
                                        @foreach ($this->categorias as $categoria)
                                            <option value="{{ $categoria->id }}">{{ $categoria->nombre }}</option>
                                        @endforeach
                                    </select>
                                @else
                                    <button type="button" wire:click="editarInline({{ $insumo->id }}, 'categoria_insumo_id')" class="block max-w-[160px] truncate text-left text-slate-700 hover:text-emerald-700">
                                        {{ $insumo->categoria?->nombre ?? 'Sin categoria' }}
                                    </button>
                                @endif
                            </td>

                            <td class="p-3 text-slate-700">
                                @if ($editing_insumo_id === (string) $insumo->id && $editing_field === 'tipo_uso')
                                    <select wire:model.defer="editing_value" wire:change="guardarEdicionInline" wire:keydown.escape="cancelarEdicionInline" class="w-full rounded-lg border-emerald-300 text-sm">
                                        <option value="receta">Receta</option>
                                        <option value="producto_unico">Producto unico</option>
                                    </select>
                                @else
                                    <button type="button" wire:click="editarInline({{ $insumo->id }}, 'tipo_uso')" class="whitespace-nowrap text-left text-slate-700 hover:text-emerald-700">
                                        {{ $insumo->inventoryItem?->is_sellable ? 'Producto unico' : 'Receta' }}
                                    </button>
                                @endif
                            </td>

                            <td class="p-3 text-slate-700">
                                @if ($editing_insumo_id === (string) $insumo->id && $editing_field === 'unidad_medida')
                                    <select wire:model.defer="editing_value" wire:change="guardarEdicionInline" wire:keydown.escape="cancelarEdicionInline" class="w-full rounded-lg border-emerald-300 text-sm">
                                        <option value="pieza">pieza</option>
                                        <option value="litro">litro</option>
                                        <option value="mililitro">mililitro</option>
                                        <option value="kg">kg</option>
                                        <option value="gramo">gramo</option>
                                        <option value="paquete">paquete</option>
                                        <option value="caja">caja</option>
                                    </select>
                                @else
                                    <button type="button" wire:click="editarInline({{ $insumo->id }}, 'unidad_medida')" class="whitespace-nowrap text-left text-slate-700 hover:text-emerald-700">
                                        {{ $insumo->unidad_medida }}
                                    </button>
                                @endif
                            </td>

                            <td class="p-3 text-slate-700">
                                @if ($editing_insumo_id === (string) $insumo->id && $editing_field === 'cantidad_actual')
                                    <input type="number" step="0.001" wire:model.defer="editing_value" wire:keydown.enter="guardarEdicionInline" wire:keydown.escape="cancelarEdicionInline" class="w-28 rounded-lg border-emerald-300 text-right text-sm">
                                    @error('editing_value')
                                        <p class="mt-1 text-xs text-red-600">{{ $message }}</p>
                                    @enderror
                                @else
                                    <button type="button" wire:click="editarInline({{ $insumo->id }}, 'cantidad_actual')" class="whitespace-nowrap text-right tabular-nums text-slate-700 hover:text-emerald-700">
                                        {{ number_format((float) $insumo->cantidad_actual, 3) }}
                                    </button>
                                @endif
                            </td>

                            <td class="p-3 text-slate-700">
                                @if ($editing_insumo_id === (string) $insumo->id && $editing_field === 'cantidad_minima')
                                    <input type="number" step="0.001" wire:model.defer="editing_value" wire:keydown.enter="guardarEdicionInline" wire:keydown.escape="cancelarEdicionInline" class="w-28 rounded-lg border-emerald-300 text-right text-sm">
                                    @error('editing_value')
                                        <p class="mt-1 text-xs text-red-600">{{ $message }}</p>
                                    @enderror
                                @else
                                    <button type="button" wire:click="editarInline({{ $insumo->id }}, 'cantidad_minima')" class="whitespace-nowrap text-right tabular-nums text-slate-700 hover:text-emerald-700">
                                        {{ number_format((float) $insumo->cantidad_minima, 3) }}
                                    </button>
                                @endif
                            </td>

                            <td class="p-3 text-slate-700">
                                @if ($editing_insumo_id === (string) $insumo->id && $editing_field === 'costo_unitario')
                                    <input type="number" step="0.01" wire:model.defer="editing_value" wire:keydown.enter="guardarEdicionInline" wire:keydown.escape="cancelarEdicionInline" class="w-28 rounded-lg border-emerald-300 text-right text-sm">
                                    @error('editing_value')
                                        <p class="mt-1 text-xs text-red-600">{{ $message }}</p>
                                    @enderror
                                @else
                                    <button type="button" wire:click="editarInline({{ $insumo->id }}, 'costo_unitario')" class="whitespace-nowrap text-right tabular-nums text-slate-700 hover:text-emerald-700">
                                        ${{ number_format((float) $insumo->costo_unitario, 2) }}
                                    </button>
                                @endif
                            </td>

                            <td class="p-3 font-medium text-slate-900">
                                ${{ number_format($valorInventario, 2) }}
                            </td>

                            <td class="p-3">
                                @if ($inventarioBajo)
                                    <span class="rounded-full bg-amber-100 px-2.5 py-1 text-xs font-medium text-amber-700">
                                        Bajo inventario
                                    </span>
                                @else
                                    <span class="rounded-full bg-emerald-100 px-2.5 py-1 text-xs font-medium text-emerald-700">
                                        Normal
                                    </span>
                                @endif
                            </td>

                            <td class="p-3">
                                <button
                                    type="button"
                                    wire:click="toggleActivo({{ $insumo->id }})"
                                    class="inline-flex items-center rounded-xl border border-slate-300 px-3 py-1.5 text-xs font-medium text-slate-700 transition hover:bg-slate-50"
                                >
                                    {{ $insumo->activo ? 'Desactivar' : 'Activar' }}
                                </button>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="10" class="p-4">
                                <div class="godslove-empty">
                                    <p class="godslove-empty-title">No hay insumos con esos filtros</p>
                                    <p class="godslove-empty-copy">Prueba limpiar la busqueda o registra un insumo base para inventario.</p>
                                </div>
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>
</div>



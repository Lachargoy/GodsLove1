<?php

use App\Models\CategoriaGasto;
use App\Models\CategoriaInsumo;
use App\Models\CategoriaProducto;
use Livewire\Component;

new class extends Component
{
    public ?int $editing_producto_id = null;
    public ?int $editing_insumo_id = null;
    public ?int $editing_gasto_id = null;
    public string $producto_nombre = '';
    public string $producto_descripcion = '';
    public string $insumo_nombre = '';
    public string $insumo_descripcion = '';
    public string $gasto_nombre = '';
    public string $gasto_descripcion = '';
    public string $estadoFilter = 'activos';

    public function categoriasProducto()
    {
        return CategoriaProducto::query()
            ->when($this->estadoFilter === 'activos', function ($query) {
                $query->where('activo', true);
            })
            ->when($this->estadoFilter === 'inactivos', function ($query) {
                $query->where('activo', false);
            })
            ->orderBy('nombre')
            ->get();
    }

    public function categoriasInsumo()
    {
        return CategoriaInsumo::query()
            ->when($this->estadoFilter === 'activos', function ($query) {
                $query->where('activo', true);
            })
            ->when($this->estadoFilter === 'inactivos', function ($query) {
                $query->where('activo', false);
            })
            ->orderBy('nombre')
            ->get();
    }

    public function categoriasGasto()
    {
        return CategoriaGasto::query()
            ->when($this->estadoFilter === 'activos', function ($query) {
                $query->where('activo', true);
            })
            ->when($this->estadoFilter === 'inactivos', function ($query) {
                $query->where('activo', false);
            })
            ->orderBy('nombre')
            ->get();
    }

    public function filtrarEstado(string $estado): void
    {
        if (! in_array($estado, ['activos', 'todos', 'inactivos'], true)) {
            return;
        }

        $this->estadoFilter = $estado;
    }

    public function guardarCategoriaProducto(): void
    {
        $validated = $this->validate([
            'producto_nombre' => ['required', 'string', 'max:255', 'unique:categoria_productos,nombre'],
            'producto_descripcion' => ['nullable', 'string'],
        ]);

        CategoriaProducto::query()->create([
            'nombre' => trim($validated['producto_nombre']),
            'descripcion' => $validated['producto_descripcion'] ?: null,
            'activo' => true,
        ]);

        $this->reset([
            'producto_nombre',
            'producto_descripcion',
        ]);

        session()->flash('success', 'Categoria de producto creada correctamente.');
    }

    public function guardarCategoriaInsumo(): void
    {
        $validated = $this->validate([
            'insumo_nombre' => ['required', 'string', 'max:255', 'unique:categoria_insumos,nombre'],
            'insumo_descripcion' => ['nullable', 'string'],
        ]);

        CategoriaInsumo::query()->create([
            'nombre' => trim($validated['insumo_nombre']),
            'descripcion' => $validated['insumo_descripcion'] ?: null,
            'activo' => true,
        ]);

        $this->reset([
            'insumo_nombre',
            'insumo_descripcion',
        ]);

        session()->flash('success', 'Categoria de insumo creada correctamente.');
    }

    public function guardarCategoriaGasto(): void
    {
        $validated = $this->validate([
            'gasto_nombre' => ['required', 'string', 'max:255', 'unique:categoria_gastos,nombre'],
            'gasto_descripcion' => ['nullable', 'string'],
        ]);

        CategoriaGasto::query()->create([
            'nombre' => trim($validated['gasto_nombre']),
            'descripcion' => $validated['gasto_descripcion'] ?: null,
            'activo' => true,
        ]);

        $this->reset([
            'gasto_nombre',
            'gasto_descripcion',
        ]);

        session()->flash('success', 'Categoria de gasto creada correctamente.');
    }

    public function editarCategoriaProducto(int $categoriaId): void
    {
        $categoria = CategoriaProducto::query()->findOrFail($categoriaId);

        $this->editing_producto_id = $categoria->id;
        $this->producto_nombre = $categoria->nombre;
        $this->producto_descripcion = $categoria->descripcion ?? '';
    }

    public function actualizarCategoriaProducto(): void
    {
        $categoria = CategoriaProducto::query()->findOrFail($this->editing_producto_id);

        $validated = $this->validate([
            'producto_nombre' => ['required', 'string', 'max:255', 'unique:categoria_productos,nombre,'.$categoria->id],
            'producto_descripcion' => ['nullable', 'string'],
        ]);

        $categoria->update([
            'nombre' => trim($validated['producto_nombre']),
            'descripcion' => $validated['producto_descripcion'] ?: null,
        ]);

        $this->cancelarEdicionProducto();

        session()->flash('success', 'Categoria de producto actualizada correctamente.');
    }

    public function cancelarEdicionProducto(): void
    {
        $this->editing_producto_id = null;
        $this->producto_nombre = '';
        $this->producto_descripcion = '';
    }

    public function toggleCategoriaProducto(int $categoriaId): void
    {
        $categoria = CategoriaProducto::query()->findOrFail($categoriaId);

        $categoria->update([
            'activo' => ! $categoria->activo,
        ]);

        session()->flash('success', $categoria->fresh()->activo
            ? 'Categoria de producto activada correctamente.'
            : 'Categoria de producto desactivada correctamente.');
    }

    public function editarCategoriaInsumo(int $categoriaId): void
    {
        $categoria = CategoriaInsumo::query()->findOrFail($categoriaId);

        $this->editing_insumo_id = $categoria->id;
        $this->insumo_nombre = $categoria->nombre;
        $this->insumo_descripcion = $categoria->descripcion ?? '';
    }

    public function actualizarCategoriaInsumo(): void
    {
        $categoria = CategoriaInsumo::query()->findOrFail($this->editing_insumo_id);

        $validated = $this->validate([
            'insumo_nombre' => ['required', 'string', 'max:255', 'unique:categoria_insumos,nombre,'.$categoria->id],
            'insumo_descripcion' => ['nullable', 'string'],
        ]);

        $categoria->update([
            'nombre' => trim($validated['insumo_nombre']),
            'descripcion' => $validated['insumo_descripcion'] ?: null,
        ]);

        $this->cancelarEdicionInsumo();

        session()->flash('success', 'Categoria de insumo actualizada correctamente.');
    }

    public function cancelarEdicionInsumo(): void
    {
        $this->editing_insumo_id = null;
        $this->insumo_nombre = '';
        $this->insumo_descripcion = '';
    }

    public function toggleCategoriaInsumo(int $categoriaId): void
    {
        $categoria = CategoriaInsumo::query()->findOrFail($categoriaId);

        $categoria->update([
            'activo' => ! $categoria->activo,
        ]);

        session()->flash('success', $categoria->fresh()->activo
            ? 'Categoria de insumo activada correctamente.'
            : 'Categoria de insumo desactivada correctamente.');
    }

    public function editarCategoriaGasto(int $categoriaId): void
    {
        $categoria = CategoriaGasto::query()->findOrFail($categoriaId);

        $this->editing_gasto_id = $categoria->id;
        $this->gasto_nombre = $categoria->nombre;
        $this->gasto_descripcion = $categoria->descripcion ?? '';
    }

    public function actualizarCategoriaGasto(): void
    {
        $categoria = CategoriaGasto::query()->findOrFail($this->editing_gasto_id);

        $validated = $this->validate([
            'gasto_nombre' => ['required', 'string', 'max:255', 'unique:categoria_gastos,nombre,'.$categoria->id],
            'gasto_descripcion' => ['nullable', 'string'],
        ]);

        $categoria->update([
            'nombre' => trim($validated['gasto_nombre']),
            'descripcion' => $validated['gasto_descripcion'] ?: null,
        ]);

        $this->cancelarEdicionGasto();

        session()->flash('success', 'Categoria de gasto actualizada correctamente.');
    }

    public function cancelarEdicionGasto(): void
    {
        $this->editing_gasto_id = null;
        $this->gasto_nombre = '';
        $this->gasto_descripcion = '';
    }

    public function toggleCategoriaGasto(int $categoriaId): void
    {
        $categoria = CategoriaGasto::query()->findOrFail($categoriaId);

        $categoria->update([
            'activo' => ! $categoria->activo,
        ]);

        session()->flash('success', $categoria->fresh()->activo
            ? 'Categoria de gasto activada correctamente.'
            : 'Categoria de gasto desactivada correctamente.');
    }
};
?>

<div class="space-y-6">
    <div class="app-hero">
        <p class="text-xs font-semibold uppercase tracking-[0.24em] text-sky-700">Configuracion</p>
        <h1 class="app-page-title">Categorias</h1>
        <p class="app-page-copy">
            Aqui puedes crear categorias para productos, insumos y gastos desde una sola pantalla.
        </p>
    </div>

    <div class="grid gap-4 md:grid-cols-3">
        <div class="app-stat-card">
            <p class="text-sm text-slate-500">Categorias de productos</p>
            <p class="mt-2 text-3xl font-semibold text-slate-900">{{ $this->categoriasProducto()->count() }}</p>
        </div>

        <div class="app-stat-card">
            <p class="text-sm text-slate-500">Categorias de insumos</p>
            <p class="mt-2 text-3xl font-semibold text-slate-900">{{ $this->categoriasInsumo()->count() }}</p>
        </div>

        <div class="app-stat-card">
            <p class="text-sm text-slate-500">Categorias de gastos</p>
            <p class="mt-2 text-3xl font-semibold text-slate-900">{{ $this->categoriasGasto()->count() }}</p>
        </div>
    </div>

    <div class="app-card flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
        <div>
            <h2 class="text-base font-semibold text-slate-900">Filtro de estado</h2>
            <p class="text-sm text-slate-500">Por defecto solo ves categorias activas. Cambia el filtro para revisar o reactivar categorias ocultas.</p>
        </div>

        <select wire:change="filtrarEstado($event.target.value)" class="w-full rounded-2xl border-slate-300 bg-white text-sm sm:w-56">
            <option value="activos" @selected($estadoFilter === 'activos')>Solo activas</option>
            <option value="todos" @selected($estadoFilter === 'todos')>Todas</option>
            <option value="inactivos" @selected($estadoFilter === 'inactivos')>Solo inactivas</option>
        </select>
    </div>

    <div class="grid gap-6 xl:grid-cols-3">
        <section class="app-card space-y-5">
            <div>
                <h2 class="text-lg font-semibold text-slate-900">Productos</h2>
                <p class="text-sm text-slate-500">Categorias para tu catalogo de venta.</p>
            </div>

            <form wire:submit="{{ $editing_producto_id ? 'actualizarCategoriaProducto' : 'guardarCategoriaProducto' }}" class="space-y-4">
                <div>
                    <label class="mb-1 block text-sm font-medium text-slate-700">Nombre</label>
                    <input type="text" wire:model="producto_nombre" class="w-full rounded-xl border-slate-300 text-sm" placeholder="Ej. Helados">
                    @error('producto_nombre')
                        <p class="mt-1 text-sm text-red-600">{{ $message }}</p>
                    @enderror
                </div>

                <div>
                    <label class="mb-1 block text-sm font-medium text-slate-700">Descripcion</label>
                    <textarea wire:model="producto_descripcion" class="w-full rounded-xl border-slate-300 text-sm" rows="3" placeholder="Opcional"></textarea>
                    @error('producto_descripcion')
                        <p class="mt-1 text-sm text-red-600">{{ $message }}</p>
                    @enderror
                </div>

                <div class="flex flex-wrap gap-3">
                    <button type="submit" class="app-primary-button">
                        {{ $editing_producto_id ? 'Guardar cambios' : 'Guardar categoria' }}
                    </button>

                    @if ($editing_producto_id)
                        <button type="button" wire:click="cancelarEdicionProducto" class="app-secondary-button">
                            Cancelar
                        </button>
                    @endif
                </div>
            </form>

            <div class="app-card-muted space-y-2">
                <p class="text-sm font-semibold text-slate-900">Existentes</p>
                @foreach ($this->categoriasProducto() as $categoria)
                    <div wire:key="categoria-producto-{{ $categoria->id }}" class="rounded-xl border border-slate-200 bg-white px-3 py-3">
                        <div class="flex items-start justify-between gap-3">
                            <div>
                                <p class="text-sm font-medium text-slate-900">{{ $categoria->nombre }}</p>
                                <p class="text-xs text-slate-500">{{ $categoria->descripcion ?: 'Sin descripcion' }}</p>
                            </div>

                            <span class="rounded-full px-2.5 py-1 text-[11px] font-medium {{ $categoria->activo ? 'bg-emerald-100 text-emerald-700' : 'bg-slate-200 text-slate-700' }}">
                                {{ $categoria->activo ? 'Activa' : 'Inactiva' }}
                            </span>
                        </div>

                        <div class="mt-3 flex flex-wrap gap-2">
                            <button type="button" wire:click="editarCategoriaProducto({{ $categoria->id }})" class="inline-flex items-center rounded-xl border border-slate-300 bg-white px-3 py-1.5 text-xs font-medium text-slate-700 transition hover:bg-slate-50">
                                Editar
                            </button>
                            <button type="button" wire:click="toggleCategoriaProducto({{ $categoria->id }})" class="inline-flex items-center rounded-xl border border-slate-300 bg-white px-3 py-1.5 text-xs font-medium text-slate-700 transition hover:bg-slate-50">
                                {{ $categoria->activo ? 'Desactivar' : 'Activar' }}
                            </button>
                        </div>
                    </div>
                @endforeach
            </div>
        </section>

        <section class="app-card space-y-5">
            <div>
                <h2 class="text-lg font-semibold text-slate-900">Insumos</h2>
                <p class="text-sm text-slate-500">Categorias para inventario y recetas.</p>
            </div>

            <form wire:submit="{{ $editing_insumo_id ? 'actualizarCategoriaInsumo' : 'guardarCategoriaInsumo' }}" class="space-y-4">
                <div>
                    <label class="mb-1 block text-sm font-medium text-slate-700">Nombre</label>
                    <input type="text" wire:model="insumo_nombre" class="w-full rounded-xl border-slate-300 text-sm" placeholder="Ej. Lacteos">
                    @error('insumo_nombre')
                        <p class="mt-1 text-sm text-red-600">{{ $message }}</p>
                    @enderror
                </div>

                <div>
                    <label class="mb-1 block text-sm font-medium text-slate-700">Descripcion</label>
                    <textarea wire:model="insumo_descripcion" class="w-full rounded-xl border-slate-300 text-sm" rows="3" placeholder="Opcional"></textarea>
                    @error('insumo_descripcion')
                        <p class="mt-1 text-sm text-red-600">{{ $message }}</p>
                    @enderror
                </div>

                <div class="flex flex-wrap gap-3">
                    <button type="submit" class="app-primary-button">
                        {{ $editing_insumo_id ? 'Guardar cambios' : 'Guardar categoria' }}
                    </button>

                    @if ($editing_insumo_id)
                        <button type="button" wire:click="cancelarEdicionInsumo" class="app-secondary-button">
                            Cancelar
                        </button>
                    @endif
                </div>
            </form>

            <div class="app-card-muted space-y-2">
                <p class="text-sm font-semibold text-slate-900">Existentes</p>
                @foreach ($this->categoriasInsumo() as $categoria)
                    <div wire:key="categoria-insumo-{{ $categoria->id }}" class="rounded-xl border border-slate-200 bg-white px-3 py-3">
                        <div class="flex items-start justify-between gap-3">
                            <div>
                                <p class="text-sm font-medium text-slate-900">{{ $categoria->nombre }}</p>
                                <p class="text-xs text-slate-500">{{ $categoria->descripcion ?: 'Sin descripcion' }}</p>
                            </div>

                            <span class="rounded-full px-2.5 py-1 text-[11px] font-medium {{ $categoria->activo ? 'bg-emerald-100 text-emerald-700' : 'bg-slate-200 text-slate-700' }}">
                                {{ $categoria->activo ? 'Activa' : 'Inactiva' }}
                            </span>
                        </div>

                        <div class="mt-3 flex flex-wrap gap-2">
                            <button type="button" wire:click="editarCategoriaInsumo({{ $categoria->id }})" class="inline-flex items-center rounded-xl border border-slate-300 bg-white px-3 py-1.5 text-xs font-medium text-slate-700 transition hover:bg-slate-50">
                                Editar
                            </button>
                            <button type="button" wire:click="toggleCategoriaInsumo({{ $categoria->id }})" class="inline-flex items-center rounded-xl border border-slate-300 bg-white px-3 py-1.5 text-xs font-medium text-slate-700 transition hover:bg-slate-50">
                                {{ $categoria->activo ? 'Desactivar' : 'Activar' }}
                            </button>
                        </div>
                    </div>
                @endforeach
            </div>
        </section>

        <section class="app-card space-y-5">
            <div>
                <h2 class="text-lg font-semibold text-slate-900">Gastos</h2>
                <p class="text-sm text-slate-500">Categorias para egresos y control financiero.</p>
            </div>

            <form wire:submit="{{ $editing_gasto_id ? 'actualizarCategoriaGasto' : 'guardarCategoriaGasto' }}" class="space-y-4">
                <div>
                    <label class="mb-1 block text-sm font-medium text-slate-700">Nombre</label>
                    <input type="text" wire:model="gasto_nombre" class="w-full rounded-xl border-slate-300 text-sm" placeholder="Ej. Servicios">
                    @error('gasto_nombre')
                        <p class="mt-1 text-sm text-red-600">{{ $message }}</p>
                    @enderror
                </div>

                <div>
                    <label class="mb-1 block text-sm font-medium text-slate-700">Descripcion</label>
                    <textarea wire:model="gasto_descripcion" class="w-full rounded-xl border-slate-300 text-sm" rows="3" placeholder="Opcional"></textarea>
                    @error('gasto_descripcion')
                        <p class="mt-1 text-sm text-red-600">{{ $message }}</p>
                    @enderror
                </div>

                <div class="flex flex-wrap gap-3">
                    <button type="submit" class="app-primary-button">
                        {{ $editing_gasto_id ? 'Guardar cambios' : 'Guardar categoria' }}
                    </button>

                    @if ($editing_gasto_id)
                        <button type="button" wire:click="cancelarEdicionGasto" class="app-secondary-button">
                            Cancelar
                        </button>
                    @endif
                </div>
            </form>

            <div class="app-card-muted space-y-2">
                <p class="text-sm font-semibold text-slate-900">Existentes</p>
                @foreach ($this->categoriasGasto() as $categoria)
                    <div wire:key="categoria-gasto-{{ $categoria->id }}" class="rounded-xl border border-slate-200 bg-white px-3 py-3">
                        <div class="flex items-start justify-between gap-3">
                            <div>
                                <p class="text-sm font-medium text-slate-900">{{ $categoria->nombre }}</p>
                                <p class="text-xs text-slate-500">{{ $categoria->descripcion ?: 'Sin descripcion' }}</p>
                            </div>

                            <span class="rounded-full px-2.5 py-1 text-[11px] font-medium {{ $categoria->activo ? 'bg-emerald-100 text-emerald-700' : 'bg-slate-200 text-slate-700' }}">
                                {{ $categoria->activo ? 'Activa' : 'Inactiva' }}
                            </span>
                        </div>

                        <div class="mt-3 flex flex-wrap gap-2">
                            <button type="button" wire:click="editarCategoriaGasto({{ $categoria->id }})" class="inline-flex items-center rounded-xl border border-slate-300 bg-white px-3 py-1.5 text-xs font-medium text-slate-700 transition hover:bg-slate-50">
                                Editar
                            </button>
                            <button type="button" wire:click="toggleCategoriaGasto({{ $categoria->id }})" class="inline-flex items-center rounded-xl border border-slate-300 bg-white px-3 py-1.5 text-xs font-medium text-slate-700 transition hover:bg-slate-50">
                                {{ $categoria->activo ? 'Desactivar' : 'Activar' }}
                            </button>
                        </div>
                    </div>
                @endforeach
            </div>
        </section>
    </div>
</div>

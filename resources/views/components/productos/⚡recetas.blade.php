<?php

use App\Models\CategoriaProducto;
use App\Models\Insumo;
use App\Models\InventoryItem;
use App\Models\Producto;
use App\Models\ProductOptionGroup;
use App\Models\ProductOptionItem;
use App\Models\ProductRecipe;
use App\Models\ProductoInsumo;
use Livewire\Component;

new class extends Component
{
    public string $producto_id = '';
    public string $insumo_id = '';
    public string $cantidad_requerida = '';
    public string $searchProducto = '';
    public string $searchInsumo = '';
    public string $categoria_producto_id = '';
    public string $product_type = 'prepared';
    public string $inventory_item_id = '';
    public string $option_group_name = 'Sabores';
    public string $required_quantity = '2';
    public string $min_quantity = '2';
    public string $max_quantity = '2';
    public string $option_inventory_item_id = '';
    public string $option_quantity_per_selection = '0.120';
    public string $option_extra_price = '0';
    public string $quick_flavor_search = 'helado';
    public array $selected_flavor_item_ids = [];

    public function mount(): void
    {
        $productoId = request()->integer('producto');

        if ($productoId > 0) {
            $producto = Producto::query()
                ->whereKey($productoId)
                ->where('activo', true)
                ->first();

            if ($producto instanceof Producto) {
                $this->seleccionarProducto($producto->id);
            }
        }
    }

    public function getProductosProperty()
    {
        return Producto::query()
            ->with('categoria')
            ->where('activo', true)
            ->when($this->searchProducto !== '', function ($query) {
                $query->where('nombre', 'like', '%'.trim($this->searchProducto).'%');
            })
            ->orderBy('nombre')
            ->get();
    }

    public function getCategoriasProperty()
    {
        return CategoriaProducto::query()
            ->where('activo', true)
            ->orderBy('nombre')
            ->get();
    }

    public function getProductoSeleccionadoProperty(): ?Producto
    {
        if ($this->producto_id === '') {
            return null;
        }

        return Producto::query()
            ->with([
                'categoria',
                'insumos.categoria',
                'inventoryItem.unit',
                'productOptionGroups.optionItems.inventoryItem.unit',
            ])
            ->find($this->producto_id);
    }

    public function getInventoryItemsProperty()
    {
        return InventoryItem::query()
            ->with('unit')
            ->where('is_active', true)
            ->orderBy('name')
            ->get();
    }

    public function getFlavorInventoryItemsProperty()
    {
        return InventoryItem::query()
            ->with(['unit', 'legacyInsumo.categoria'])
            ->where('is_active', true)
            ->where('is_consumable', true)
            ->where('legacy_table', 'insumos')
            ->when(trim($this->quick_flavor_search) !== '', function ($query) {
                $search = trim($this->quick_flavor_search);

                $query->where(function ($subQuery) use ($search) {
                    $subQuery->where('name', 'like', '%'.$search.'%')
                        ->orWhereHas('legacyInsumo', function ($insumoQuery) use ($search) {
                            $insumoQuery->where('nombre', 'like', '%'.$search.'%')
                                ->orWhereHas('categoria', function ($categoriaQuery) use ($search) {
                                    $categoriaQuery->where('nombre', 'like', '%'.$search.'%');
                                });
                        });
                });
            })
            ->orderBy('name')
            ->limit(80)
            ->get();
    }

    public function getInsumosDisponiblesProperty()
    {
        $insumosRelacionados = $this->productoSeleccionado?->insumos->pluck('id')->all() ?? [];

        return Insumo::query()
            ->with('categoria')
            ->where('activo', true)
            ->when($this->searchInsumo !== '', function ($query) {
                $query->where('nombre', 'like', '%'.trim($this->searchInsumo).'%');
            })
            ->when($insumosRelacionados !== [], function ($query) use ($insumosRelacionados) {
                $query->whereNotIn('id', $insumosRelacionados);
            })
            ->orderBy('nombre')
            ->get();
    }

    public function getProductosSinRecetaProperty()
    {
        return Producto::query()
            ->where('activo', true)
            ->whereDoesntHave('insumos')
            ->whereDoesntHave('productRecipes')
            ->whereDoesntHave('productOptionGroups')
            ->orderBy('nombre')
            ->get();
    }

    public function getProductosConRecetaProperty()
    {
        return Producto::query()
            ->where('activo', true)
            ->where(function ($query) {
                $query
                    ->has('insumos')
                    ->orHas('productRecipes')
                    ->orHas('productOptionGroups');
            })
            ->orderBy('nombre')
            ->get();
    }

    public function getCostoRecetaProperty(): float
    {
        if (! $this->productoSeleccionado instanceof Producto) {
            return 0;
        }

        return round(
            $this->productoSeleccionado->insumos->sum(
                fn (Insumo $insumo) => (float) $insumo->pivot->cantidad_requerida * (float) $insumo->costo_unitario
            ),
            2,
        );
    }

    public function getMargenEstimadoProperty(): float
    {
        if (! $this->productoSeleccionado instanceof Producto) {
            return 0;
        }

        return round((float) $this->productoSeleccionado->precio_venta - $this->costoReceta, 2);
    }

    public function seleccionarProducto(int $productoId): void
    {
        $producto = Producto::query()->findOrFail($productoId);

        $this->producto_id = (string) $productoId;
        $this->categoria_producto_id = $producto->categoria_producto_id ? (string) $producto->categoria_producto_id : '';
        $this->product_type = $producto->product_type ?: 'prepared';
        $this->inventory_item_id = $producto->inventory_item_id ? (string) $producto->inventory_item_id : '';
        $this->insumo_id = '';
        $this->cantidad_requerida = '';
        $this->searchInsumo = '';
        $this->selected_flavor_item_ids = [];
        $this->resetConfiguracionOpciones();
    }

    public function actualizarConfiguracionProducto(): void
    {
        $validated = $this->validate([
            'producto_id' => ['required', 'exists:productos,id'],
            'categoria_producto_id' => ['nullable', 'exists:categoria_productos,id'],
            'product_type' => ['required', 'in:simple,prepared,configurable'],
            'inventory_item_id' => ['nullable', 'exists:inventory_items,id'],
        ]);

        if ($validated['product_type'] === 'simple' && blank($validated['inventory_item_id'])) {
            $this->addError('inventory_item_id', 'Selecciona el inventario que descuenta este producto simple.');

            return;
        }

        $producto = Producto::query()->findOrFail($validated['producto_id']);

        $producto->update([
            'categoria_producto_id' => $validated['categoria_producto_id'] ?: null,
            'product_type' => $validated['product_type'],
            'inventory_item_id' => $validated['product_type'] === 'simple' ? $validated['inventory_item_id'] : null,
        ]);

        if ($validated['product_type'] === 'simple') {
            InventoryItem::query()
                ->whereKey($validated['inventory_item_id'])
                ->update(['is_sellable' => true]);
        }

        session()->flash('success', 'ConfiguraciÃ³n del producto actualizada.');
    }

    public function aplicarPresetSabores(int $cantidad): void
    {
        $cantidad = max(1, min(12, $cantidad));

        $this->product_type = 'configurable';
        $this->inventory_item_id = '';
        $this->option_group_name = 'Sabores';
        $this->required_quantity = (string) $cantidad;
        $this->min_quantity = (string) $cantidad;
        $this->max_quantity = (string) $cantidad;
        $this->option_quantity_per_selection = $this->option_quantity_per_selection !== '' ? $this->option_quantity_per_selection : '0.120';

        $this->resetErrorBag([
            'required_quantity',
            'min_quantity',
            'max_quantity',
        ]);
    }

    public function crearGrupoOpciones(): void
    {
        $validated = $this->validate([
            'producto_id' => ['required', 'exists:productos,id'],
            'option_group_name' => ['required', 'string', 'max:255'],
            'required_quantity' => ['required', 'numeric', 'min:0.001'],
            'min_quantity' => ['nullable', 'numeric', 'min:0'],
            'max_quantity' => ['nullable', 'numeric', 'min:0.001'],
        ]);

        $reglas = $this->obtenerReglasGrupoDesdeFormulario();

        if ($reglas === null) {
            return;
        }

        ProductOptionGroup::query()->updateOrCreate(
            [
                'product_id' => $validated['producto_id'],
                'name' => trim($validated['option_group_name']),
            ],
            $reglas,
        );

        Producto::query()
            ->whereKey($validated['producto_id'])
            ->update(['product_type' => 'configurable', 'inventory_item_id' => null]);

        $this->product_type = 'configurable';
        $this->inventory_item_id = '';

        session()->flash('success', 'Grupo configurable guardado.');
    }

    public function agregarOpcionGrupo(int $groupId): void
    {
        $validated = $this->validate([
            'option_inventory_item_id' => ['required', 'exists:inventory_items,id'],
            'option_quantity_per_selection' => ['required', 'numeric', 'min:0.001'],
            'option_extra_price' => ['nullable', 'numeric', 'min:0'],
        ]);

        $group = ProductOptionGroup::query()
            ->where('product_id', $this->producto_id)
            ->findOrFail($groupId);

        ProductOptionItem::query()->updateOrCreate(
            [
                'product_option_group_id' => $group->id,
                'inventory_item_id' => $validated['option_inventory_item_id'],
            ],
            [
                'quantity_per_selection' => (float) $validated['option_quantity_per_selection'],
                'extra_price' => filled($validated['option_extra_price']) ? (float) $validated['option_extra_price'] : null,
                'is_active' => true,
            ],
        );

        $this->reset([
            'option_inventory_item_id',
        ]);

        $this->option_quantity_per_selection = '0.120';
        $this->option_extra_price = '0';

        session()->flash('success', 'OpciÃ³n configurable agregada.');
    }

    public function quitarOpcion(int $optionItemId): void
    {
        ProductOptionItem::query()->findOrFail($optionItemId)->delete();

        session()->flash('success', 'OpciÃ³n configurable eliminada.');
    }

    public function quitarGrupoOpciones(int $groupId): void
    {
        $group = ProductOptionGroup::query()
            ->where('product_id', $this->producto_id)
            ->findOrFail($groupId);

        $group->optionItems()->delete();
        $group->delete();

        session()->flash('success', 'Grupo configurable eliminado.');
    }

    public function actualizarGrupoOpciones(int $groupId, string $field, mixed $value): void
    {
        $group = ProductOptionGroup::query()
            ->where('product_id', $this->producto_id)
            ->findOrFail($groupId);

        if ($field === 'name') {
            $validated = validator(
                ['value' => $value],
                ['value' => ['required', 'string', 'max:255']],
            )->validate();

            $group->update([
                'name' => trim($validated['value']),
            ]);

            session()->flash('success', 'Nombre del grupo actualizado.');

            return;
        }

        if (! in_array($field, ['required_quantity', 'min_quantity', 'max_quantity'], true)) {
            return;
        }

        $rules = $field === 'required_quantity'
            ? ['required', 'numeric', 'min:0.001']
            : ['nullable', 'numeric', 'min:0'];

        $validated = validator(
            ['value' => $value],
            ['value' => $rules],
        )->validate();

        $reglas = [
            'required_quantity' => (float) $group->required_quantity,
            'min_quantity' => filled($group->min_quantity) ? (float) $group->min_quantity : (float) $group->required_quantity,
            'max_quantity' => filled($group->max_quantity) ? (float) $group->max_quantity : (float) $group->required_quantity,
        ];

        $reglas[$field] = filled($validated['value'])
            ? (float) $validated['value']
            : (float) $group->required_quantity;

        if ($field === 'required_quantity') {
            $reglas['min_quantity'] = min($reglas['min_quantity'], $reglas['required_quantity']);
            $reglas['max_quantity'] = max($reglas['max_quantity'], $reglas['required_quantity']);
        }

        if ($field === 'min_quantity') {
            $reglas['required_quantity'] = max($reglas['required_quantity'], $reglas['min_quantity']);
            $reglas['max_quantity'] = max($reglas['max_quantity'], $reglas['required_quantity']);
        }

        if ($field === 'max_quantity') {
            $reglas['required_quantity'] = min($reglas['required_quantity'], $reglas['max_quantity']);
            $reglas['min_quantity'] = min($reglas['min_quantity'], $reglas['required_quantity']);
        }

        $group->update($reglas);

        session()->flash('success', 'Regla del grupo actualizada.');
    }

    public function actualizarOpcionConfigurable(int $optionItemId, string $field, mixed $value): void
    {
        $option = ProductOptionItem::query()
            ->whereHas('productOptionGroup', function ($query) {
                $query->where('product_id', $this->producto_id);
            })
            ->findOrFail($optionItemId);

        if ($field === 'inventory_item_id') {
            $validated = validator(
                ['value' => $value],
                ['value' => ['required', 'exists:inventory_items,id']],
            )->validate();

            $option->update([
                'inventory_item_id' => (int) $validated['value'],
            ]);

            session()->flash('success', 'Sabor actualizado.');

            return;
        }

        if (! in_array($field, ['quantity_per_selection', 'extra_price'], true)) {
            return;
        }

        $rules = $field === 'quantity_per_selection'
            ? ['required', 'numeric', 'min:0.001']
            : ['nullable', 'numeric', 'min:0'];

        $validated = validator(
            ['value' => $value],
            ['value' => $rules],
        )->validate();

        $option->update([
            $field => filled($validated['value']) ? (float) $validated['value'] : null,
        ]);

        session()->flash('success', 'Opcion configurable actualizada.');
    }

    public function agregarSaboresPorBusqueda(): void
    {
        if ($this->producto_id === '') {
            $this->addError('quick_flavor_search', 'Selecciona un producto configurable.');

            return;
        }

        $producto = Producto::query()->findOrFail($this->producto_id);
        $reglas = $this->obtenerReglasGrupoDesdeFormulario() ?? [
            'required_quantity' => 2,
            'min_quantity' => 2,
            'max_quantity' => 2,
        ];

        $group = ProductOptionGroup::query()->firstOrCreate(
            [
                'product_id' => $producto->id,
                'name' => 'Sabores',
            ],
            $reglas,
        );

        $search = trim($this->quick_flavor_search);

        $items = InventoryItem::query()
            ->where('is_active', true)
            ->when($search !== '', fn ($query) => $query->where('name', 'like', '%'.$search.'%'))
            ->orderBy('name')
            ->get();

        if ($items->isEmpty()) {
            $this->addError('quick_flavor_search', 'No encontrÃ© inventario activo con ese texto.');

            return;
        }

        foreach ($items as $item) {
            ProductOptionItem::query()->updateOrCreate(
                [
                    'product_option_group_id' => $group->id,
                    'inventory_item_id' => $item->id,
                ],
                [
                    'quantity_per_selection' => 0.120,
                    'extra_price' => null,
                    'is_active' => true,
                ],
            );
        }

        $producto->update([
            'product_type' => 'configurable',
            'inventory_item_id' => null,
        ]);

        $this->product_type = 'configurable';

        session()->flash('success', "Se agregaron {$items->count()} sabores configurables.");
    }

    public function agregarSaboresSeleccionados(): void
    {
        if ($this->producto_id === '') {
            $this->addError('selected_flavor_item_ids', 'Selecciona un producto configurable.');

            return;
        }

        $selectedIds = collect($this->selected_flavor_item_ids)
            ->filter()
            ->map(fn ($id): int => (int) $id)
            ->unique()
            ->values();

        if ($selectedIds->isEmpty()) {
            $this->addError('selected_flavor_item_ids', 'Selecciona al menos un sabor.');

            return;
        }

        $producto = Producto::query()->findOrFail($this->producto_id);
        $reglas = $this->obtenerReglasGrupoDesdeFormulario() ?? [
            'required_quantity' => 2,
            'min_quantity' => 2,
            'max_quantity' => 2,
        ];

        $group = ProductOptionGroup::query()->firstOrCreate(
            [
                'product_id' => $producto->id,
                'name' => 'Sabores',
            ],
            $reglas,
        );

        $items = InventoryItem::query()
            ->whereIn('id', $selectedIds)
            ->where('is_active', true)
            ->get();

        foreach ($items as $item) {
            ProductOptionItem::query()->updateOrCreate(
                [
                    'product_option_group_id' => $group->id,
                    'inventory_item_id' => $item->id,
                ],
                [
                    'quantity_per_selection' => 0.120,
                    'extra_price' => null,
                    'is_active' => true,
                ],
            );
        }

        $producto->update([
            'product_type' => 'configurable',
            'inventory_item_id' => null,
        ]);

        $this->product_type = 'configurable';
        $this->selected_flavor_item_ids = [];

        session()->flash('success', "Se agregaron {$items->count()} sabores seleccionados.");
    }

    public function agregarInsumo(): void
    {
        $validated = $this->validate([
            'producto_id' => ['required', 'exists:productos,id'],
            'insumo_id' => ['required', 'exists:insumos,id'],
            'cantidad_requerida' => ['required', 'numeric', 'min:0.001'],
        ]);

        $producto = Producto::query()
            ->whereKey($validated['producto_id'])
            ->where('activo', true)
            ->firstOrFail();

        $insumo = Insumo::query()
            ->whereKey($validated['insumo_id'])
            ->where('activo', true)
            ->firstOrFail();

        if ($producto->insumos()->where('insumo_id', $insumo->id)->exists()) {
            $this->addError('insumo_id', 'Ese insumo ya está agregado a la receta.');

            return;
        }

        ProductoInsumo::query()->updateOrCreate(
            [
                'producto_id' => $producto->id,
                'insumo_id' => $insumo->id,
            ],
            [
                'cantidad_requerida' => (float) $validated['cantidad_requerida'],
            ],
        );

        $this->sincronizarRecetaNueva($producto, $insumo, (float) $validated['cantidad_requerida']);

        $this->recalcularCostoProducto($producto);

        $this->reset([
            'insumo_id',
            'cantidad_requerida',
        ]);

        session()->flash('success', 'Insumo agregado a la receta correctamente.');
    }

    public function actualizarCantidad(int $insumoId, mixed $cantidad): void
    {
        $cantidadActualizada = (float) $cantidad;

        if ($this->producto_id === '' || $cantidadActualizada < 0.001) {
            return;
        }

        $producto = Producto::query()->findOrFail($this->producto_id);

        $producto->insumos()->updateExistingPivot($insumoId, [
            'cantidad_requerida' => $cantidadActualizada,
        ]);

        $insumo = Insumo::query()->find($insumoId);

        if ($insumo instanceof Insumo) {
            $this->sincronizarRecetaNueva($producto, $insumo, $cantidadActualizada);
        }

        $this->recalcularCostoProducto($producto);

        session()->flash('success', 'Cantidad requerida actualizada.');
    }

    public function quitarInsumo(int $insumoId): void
    {
        if ($this->producto_id === '') {
            return;
        }

        $producto = Producto::query()->findOrFail($this->producto_id);
        $producto->insumos()->detach($insumoId);

        $insumo = Insumo::query()->find($insumoId);

        if ($insumo instanceof Insumo && $insumo->inventory_item_id) {
            ProductRecipe::query()
                ->where('product_id', $producto->id)
                ->where('inventory_item_id', $insumo->inventory_item_id)
                ->delete();
        }

        $this->recalcularCostoProducto($producto);

        session()->flash('success', 'Insumo quitado de la receta correctamente.');
    }

    public function recalcularCostoProducto(Producto $producto): float
    {
        $producto->load('insumos');

        $costoCalculado = round(
            $producto->insumos->sum(
                fn (Insumo $insumo) => (float) $insumo->pivot->cantidad_requerida * (float) $insumo->costo_unitario
            ),
            2,
        );

        $producto->update([
            'costo_estimado' => $costoCalculado,
        ]);

        return $costoCalculado;
    }

    private function sincronizarRecetaNueva(Producto $producto, Insumo $insumo, float $cantidadRequerida): void
    {
        if (! $insumo->inventory_item_id) {
            return;
        }

        ProductRecipe::query()->updateOrCreate(
            [
                'product_id' => $producto->id,
                'inventory_item_id' => $insumo->inventory_item_id,
            ],
            [
                'quantity' => $cantidadRequerida,
            ],
        );

        $producto->update([
            'product_type' => $producto->product_type === 'configurable' ? 'configurable' : 'prepared',
        ]);
    }

    /**
     * @return array{required_quantity: float, min_quantity: float, max_quantity: float}|null
     */
    private function obtenerReglasGrupoDesdeFormulario(): ?array
    {
        $required = (float) ($this->required_quantity !== '' ? $this->required_quantity : 2);
        $min = (float) ($this->min_quantity !== '' ? $this->min_quantity : $required);
        $max = (float) ($this->max_quantity !== '' ? $this->max_quantity : $required);

        $reglas = [
            'required_quantity' => $required,
            'min_quantity' => $min,
            'max_quantity' => $max,
        ];

        if (! $this->reglasGrupoSonCoherentes($reglas)) {
            $this->addError('required_quantity', 'La regla debe quedar: minimo <= requeridas <= maximo.');

            return null;
        }

        return $reglas;
    }

    /**
     * @param  array{required_quantity: float, min_quantity: float, max_quantity: float}  $reglas
     */
    private function reglasGrupoSonCoherentes(array $reglas): bool
    {
        return $reglas['min_quantity'] <= $reglas['required_quantity']
            && $reglas['required_quantity'] <= $reglas['max_quantity'];
    }

    private function resetConfiguracionOpciones(): void
    {
        $this->option_group_name = 'Sabores';
        $this->required_quantity = '2';
        $this->min_quantity = '2';
        $this->max_quantity = '2';
        $this->option_inventory_item_id = '';
        $this->option_quantity_per_selection = '0.120';
        $this->option_extra_price = '0';
        $this->selected_flavor_item_ids = [];
    }
};
?>

<div class="space-y-6">
    <div>
        <h1 class="text-2xl font-bold text-gray-900">Recetas de productos</h1>
        <p class="text-sm text-gray-500">
            Define qué insumos consume cada producto para descontar inventario automáticamente al vender.
        </p>
    </div>

    @if (session('success'))
        <div class="rounded-xl border border-green-200 bg-green-50 px-4 py-3 text-sm text-green-700">
            {{ session('success') }}
        </div>
    @endif

    <div class="grid gap-4 md:grid-cols-2 xl:grid-cols-4">
        <div class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm">
            <p class="text-sm text-slate-500">Productos activos</p>
            <p class="mt-2 text-3xl font-semibold text-slate-900">{{ $this->productos->count() }}</p>
        </div>

        <div class="rounded-2xl border border-emerald-200 bg-emerald-50 p-5 shadow-sm">
            <p class="text-sm text-emerald-700">Con receta</p>
            <p class="mt-2 text-3xl font-semibold text-emerald-900">{{ $this->productosConReceta->count() }}</p>
        </div>

        <div class="rounded-2xl border border-amber-200 bg-amber-50 p-5 shadow-sm">
            <p class="text-sm text-amber-700">Sin receta</p>
            <p class="mt-2 text-3xl font-semibold text-amber-900">{{ $this->productosSinReceta->count() }}</p>
        </div>

        <div class="rounded-2xl border border-sky-200 bg-sky-50 p-5 shadow-sm">
            <p class="text-sm text-sky-700">Producto seleccionado</p>
            <p class="mt-2 text-lg font-semibold text-sky-900">
                {{ $this->productoSeleccionado?->nombre ?? 'Ninguno' }}
            </p>
        </div>
    </div>

    <div class="grid gap-6 xl:grid-cols-[0.95fr_1.05fr]">
        <div class="space-y-6">
            <div class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm">
                <div class="mb-4">
                    <h2 class="text-lg font-semibold text-slate-900">Seleccionar producto</h2>
                    <p class="text-sm text-slate-500">Busca un producto y abre su receta.</p>
                </div>

                <div class="mb-4">
                    <label class="mb-1 block text-sm font-medium text-slate-700">Buscar producto</label>
                    <input
                        type="text"
                        wire:model.live="searchProducto"
                        class="w-full rounded-xl border-slate-300 text-sm"
                        placeholder="Ej. Cono sencillo"
                    >
                </div>

                <div class="space-y-3">
                    @forelse ($this->productos as $producto)
                        <button
                            type="button"
                            wire:key="producto-receta-{{ $producto->id }}"
                            wire:click="seleccionarProducto({{ $producto->id }})"
                            class="flex w-full items-center justify-between rounded-xl border px-4 py-3 text-left transition {{ (string) $producto->id === $producto_id ? 'border-sky-300 bg-sky-50' : 'border-slate-200 bg-white hover:bg-slate-50' }}"
                        >
                            <div>
                                <div class="font-medium text-slate-900">{{ $producto->nombre }}</div>
                                <div class="text-sm text-slate-500">{{ $producto->categoria?->nombre ?? 'Sin categoría' }}</div>
                            </div>

                            @if ($producto->insumos()->exists())
                                <span class="rounded-full bg-emerald-100 px-2.5 py-1 text-xs font-medium text-emerald-700">
                                    Con receta
                                </span>
                            @else
                                <span class="rounded-full bg-amber-100 px-2.5 py-1 text-xs font-medium text-amber-700">
                                    Sin receta
                                </span>
                            @endif
                        </button>
                    @empty
                        <div class="rounded-xl border border-slate-200 bg-slate-50 px-4 py-6 text-center text-sm text-slate-500">
                            No hay productos activos disponibles.
                        </div>
                    @endforelse
                </div>
            </div>

            <div class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm">
                <div class="mb-4">
                    <h2 class="text-lg font-semibold text-slate-900">Productos sin receta</h2>
                    <p class="text-sm text-slate-500">Úsalos como guía para configurar pendientes.</p>
                </div>

                <div class="space-y-2">
                    @forelse ($this->productosSinReceta as $producto)
                        <div wire:key="sin-receta-{{ $producto->id }}" class="rounded-xl border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-900">
                            {{ $producto->nombre }}
                        </div>
                    @empty
                        <div class="rounded-xl border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm text-emerald-700">
                            Todos los productos activos ya tienen receta.
                        </div>
                    @endforelse
                </div>
            </div>
        </div>

        <div class="space-y-6">
            <div class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm">
                @if (! $this->productoSeleccionado)
                    <div class="rounded-xl border border-dashed border-slate-300 bg-slate-50 px-4 py-8 text-center text-sm text-slate-500">
                        Selecciona un producto para configurar su receta.
                    </div>
                @else
                    <div class="flex flex-col gap-4">
                        <div>
                            <h2 class="text-lg font-semibold text-slate-900">{{ $this->productoSeleccionado->nombre }}</h2>
                            <p class="text-sm text-slate-500">{{ $this->productoSeleccionado->categoria?->nombre ?? 'Sin categoría' }}</p>
                        </div>

                        <div class="grid gap-4 md:grid-cols-2 xl:grid-cols-4">
                            <div class="rounded-xl bg-slate-50 p-4">
                                <p class="text-xs uppercase tracking-wide text-slate-500">Precio de venta</p>
                                <p class="mt-2 text-lg font-semibold text-slate-900">${{ number_format((float) $this->productoSeleccionado->precio_venta, 2) }}</p>
                            </div>

                            <div class="rounded-xl bg-slate-50 p-4">
                                <p class="text-xs uppercase tracking-wide text-slate-500">Costo receta</p>
                                <p class="mt-2 text-lg font-semibold text-slate-900">${{ number_format($this->costoReceta, 2) }}</p>
                            </div>

                            <div class="rounded-xl bg-slate-50 p-4">
                                <p class="text-xs uppercase tracking-wide text-slate-500">Costo guardado</p>
                                <p class="mt-2 text-lg font-semibold text-slate-900">${{ number_format((float) $this->productoSeleccionado->costo_estimado, 2) }}</p>
                            </div>

                            <div class="rounded-xl bg-slate-50 p-4">
                                <p class="text-xs uppercase tracking-wide text-slate-500">Margen estimado</p>
                                <p class="mt-2 text-lg font-semibold text-slate-900">${{ number_format($this->margenEstimado, 2) }}</p>
                            </div>
                        </div>
                    </div>
                @endif
            </div>

            @if ($this->productoSeleccionado)
                <div class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm">
                    <div class="mb-4">
                        <h2 class="text-lg font-semibold text-slate-900">Configuracion del producto</h2>
                        <p class="text-sm text-slate-500">
                            Define categoria, tipo de venta y, si aplica, el inventario directo que descuenta.
                        </p>
                    </div>

                    <form wire:submit="actualizarConfiguracionProducto" class="grid gap-4 lg:grid-cols-[220px_220px_1fr_auto]">
                        <div>
                            <label class="mb-1 block text-sm font-medium text-slate-700">Categoria</label>
                            <select wire:model.live="categoria_producto_id" class="w-full rounded-xl border-slate-300 text-sm">
                                <option value="">Sin categoria</option>

                                @foreach ($this->categorias as $categoria)
                                    <option value="{{ $categoria->id }}">{{ $categoria->nombre }}</option>
                                @endforeach
                            </select>

                            @error('categoria_producto_id')
                                <p class="mt-1 text-sm text-red-600">{{ $message }}</p>
                            @enderror
                        </div>

                        <div>
                            <label class="mb-1 block text-sm font-medium text-slate-700">Tipo</label>
                            <select wire:model.live="product_type" class="w-full rounded-xl border-slate-300 text-sm">
                                <option value="prepared">Preparado con receta</option>
                                <option value="simple">Simple: descuenta directo</option>
                                <option value="configurable">Configurable</option>
                            </select>

                            @error('product_type')
                                <p class="mt-1 text-sm text-red-600">{{ $message }}</p>
                            @enderror
                        </div>

                        <div>
                            <label class="mb-1 block text-sm font-medium text-slate-700">Inventario directo</label>
                            <select
                                wire:model="inventory_item_id"
                                @disabled($product_type !== 'simple')
                                class="w-full rounded-xl border-slate-300 text-sm disabled:bg-slate-100 disabled:text-slate-400"
                            >
                                <option value="">Solo para productos simples</option>

                                @foreach ($this->inventoryItems as $item)
                                    <option value="{{ $item->id }}">
                                        {{ $item->name }} ({{ $item->unit?->abbreviation ?? 'unidad' }}) · stock {{ number_format((float) $item->current_stock, 3) }}
                                    </option>
                                @endforeach
                            </select>

                            @error('inventory_item_id')
                                <p class="mt-1 text-sm text-red-600">{{ $message }}</p>
                            @enderror
                        </div>

                        <div class="flex items-end">
                            <button
                                type="submit"
                                class="inline-flex items-center rounded-xl bg-sky-600 px-4 py-2 text-sm font-medium text-white transition hover:bg-sky-700"
                            >
                                Guardar cambios
                            </button>
                        </div>
                    </form>

                    @if ($product_type === 'configurable')
                        <div class="mt-4 rounded-2xl border border-indigo-200 bg-indigo-50 px-4 py-3 text-sm text-indigo-800">
                            El producto principal puede quedarse en la categoria que quieras, por ejemplo <span class="font-semibold">Helados</span>.
                            Los sabores se cargan aparte como opciones del grupo configurable y no cambian esa categoria.
                        </div>
                    @endif

                    <div class="mt-4 rounded-xl border border-slate-200 bg-slate-50 px-4 py-3 text-sm text-slate-600">
                        Tipo actual:
                        <span class="font-semibold text-slate-900">
                            @if ($this->productoSeleccionado->product_type === 'simple')
                                Simple
                            @elseif ($this->productoSeleccionado->product_type === 'configurable')
                                Configurable
                            @else
                                Preparado
                            @endif
                        </span>

                        @if ($this->productoSeleccionado->inventoryItem)
                            · descuenta {{ $this->productoSeleccionado->inventoryItem->name }}
                        @endif
                    </div>
                </div>

                @if ($product_type === 'configurable')
                    <div x-data="{ guiaAbierta: true, saboresAbiertos: true, gruposAbiertos: true }" class="rounded-2xl border border-slate-200 bg-white shadow-sm">
                        <div class="border-b border-slate-200 px-5 py-4">
                            <div class="flex flex-col gap-3 lg:flex-row lg:items-start lg:justify-between">
                                <div>
                                    <p class="text-xs font-semibold uppercase tracking-[0.18em] text-emerald-600">Producto configurable</p>
                                    <h2 class="mt-1 text-xl font-semibold text-slate-950">Editar receta y sabores</h2>
                                    <p class="mt-1 text-xs font-semibold uppercase tracking-[0.16em] text-slate-400">Opciones configurables</p>
                                    <p class="mt-1 max-w-3xl text-sm leading-6 text-slate-500">
                                        Configura cuantas bolas debe elegir el vendedor, cuanto inventario descuenta cada bola y que sabores aparecen en venta.
                                    </p>
                                </div>

                                <div class="rounded-xl border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm text-emerald-900">
                                    <p class="font-semibold">Regla actual</p>
                                    <p class="mt-1">
                                        {{ $required_quantity ?: '0' }} seleccion(es) de {{ $option_group_name ?: 'Sabores' }}
                                        @if ($option_quantity_per_selection !== '')
                                            · {{ $option_quantity_per_selection }} por bola
                                        @endif
                                    </p>
                                </div>
                            </div>
                        </div>

                        <div class="border-b border-slate-200 px-5 py-4">
                            <button type="button" x-on:click="guiaAbierta = ! guiaAbierta" class="flex w-full items-center justify-between gap-3 text-left">
                                <div>
                                    <h3 class="text-sm font-semibold text-slate-950">1. Define como se vende</h3>
                                    <p class="text-xs text-slate-500">Usa un preset y luego ajusta los numeros si necesitas algo especial.</p>
                                </div>
                                <span class="text-xs font-semibold uppercase tracking-[0.14em] text-slate-400" x-text="guiaAbierta ? 'Ocultar' : 'Mostrar'"></span>
                            </button>

                            <div x-show="guiaAbierta" x-collapse class="mt-4 space-y-4">
                                <div class="grid gap-3 md:grid-cols-3">
                                    @foreach ([1 => 'Helado sencillo', 2 => 'Helado doble', 3 => 'Helado triple'] as $cantidad => $titulo)
                                        <button
                                            type="button"
                                            wire:click="aplicarPresetSabores({{ $cantidad }})"
                                            class="rounded-xl border px-4 py-3 text-left transition {{ (int) $required_quantity === $cantidad && (int) $min_quantity === $cantidad && (int) $max_quantity === $cantidad ? 'border-emerald-500 bg-emerald-50 text-emerald-950' : 'border-slate-200 bg-slate-50 text-slate-800 hover:border-emerald-300 hover:bg-emerald-50/60' }}"
                                        >
                                            <p class="text-sm font-semibold">{{ $titulo }}</p>
                                            <p class="mt-1 text-xs leading-5 text-slate-500">
                                                Requiere {{ $cantidad }} bola(s). Puede ser el mismo sabor repetido o combinado.
                                            </p>
                                        </button>
                                    @endforeach
                                </div>

                                <div class="grid gap-4 lg:grid-cols-[minmax(0,1.1fr)_minmax(280px,0.9fr)]">
                                    <form wire:submit="crearGrupoOpciones" class="grid gap-3 rounded-xl border border-slate-200 bg-slate-50 p-4 md:grid-cols-2 xl:grid-cols-[1fr_150px_150px_150px_auto]">
                                        <div class="md:col-span-2 xl:col-span-1">
                                            <label class="mb-1 block text-sm font-medium text-slate-700">Nombre del grupo</label>
                                            <input type="text" wire:model="option_group_name" class="w-full rounded-xl border-slate-300 text-sm" placeholder="Sabores">
                                            <p class="mt-1 text-xs text-slate-500">Asi lo vera venta: Sabores, Toppings, Cubiertas, etc.</p>

                                            @error('option_group_name')
                                                <p class="mt-1 text-sm text-red-600">{{ $message }}</p>
                                            @enderror
                                        </div>

                                        <div>
                                            <label class="mb-1 block text-sm font-medium text-slate-700">Bolas requeridas</label>
                                            <input type="number" step="0.001" wire:model.live="required_quantity" class="w-full rounded-xl border-slate-300 text-sm">

                                            @error('required_quantity')
                                                <p class="mt-1 text-sm text-red-600">{{ $message }}</p>
                                            @enderror
                                        </div>

                                        <div>
                                            <label class="mb-1 block text-sm font-medium text-slate-700">Minimo permitido</label>
                                            <input type="number" step="0.001" wire:model.live="min_quantity" class="w-full rounded-xl border-slate-300 text-sm">

                                            @error('min_quantity')
                                                <p class="mt-1 text-sm text-red-600">{{ $message }}</p>
                                            @enderror
                                        </div>

                                        <div>
                                            <label class="mb-1 block text-sm font-medium text-slate-700">Maximo permitido</label>
                                            <input type="number" step="0.001" wire:model.live="max_quantity" class="w-full rounded-xl border-slate-300 text-sm">

                                            @error('max_quantity')
                                                <p class="mt-1 text-sm text-red-600">{{ $message }}</p>
                                            @enderror
                                        </div>

                                        <div class="flex items-end">
                                            <button type="submit" class="inline-flex w-full items-center justify-center rounded-xl bg-slate-950 px-4 py-2 text-sm font-medium text-white transition hover:bg-slate-800">
                                                Guardar regla
                                            </button>
                                        </div>
                                    </form>

                                    <div class="rounded-xl border border-slate-200 bg-white p-4">
                                        <p class="text-sm font-semibold text-slate-950">Como se interpreta</p>
                                        <div class="mt-3 space-y-3 text-sm leading-6 text-slate-600">
                                            <p>
                                                Si pones <span class="font-semibold text-slate-900">{{ $required_quantity ?: '0' }}</span> requeridas,
                                                venta solo permite agregar el producto cuando el total elegido llegue a esa cantidad.
                                            </p>
                                            <p>
                                                Si minimo y maximo son iguales a <span class="font-semibold text-slate-900">{{ $max_quantity ?: '0' }}</span>,
                                                puedes vender {{ $max_quantity ?: '0' }} del mismo sabor o combinarlos, sin pasar ese total.
                                            </p>
                                            <p>
                                                Cantidad por bola / seleccion: si el insumo esta en litros, una bola puede consumir 0.120.
                                                Ejemplo con {{ $required_quantity ?: '0' }} bola(s):
                                                <span class="font-semibold text-slate-900">
                                                    {{ number_format((float) ($required_quantity ?: 0) * (float) ($option_quantity_per_selection ?: 0), 3) }}
                                                </span>
                                                del insumo elegido.
                                            </p>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <div class="border-b border-slate-200 px-5 py-4">
                            <button type="button" x-on:click="saboresAbiertos = ! saboresAbiertos" class="flex w-full items-center justify-between gap-3 text-left">
                                <div>
                                    <h3 class="text-sm font-semibold text-slate-950">2. Agrega sabores desde inventario</h3>
                                    <p class="text-xs text-slate-500">
                                        Busca por nombre o categoria. Aunque el insumo este en Otros, aqui lo puedes usar como sabor si tiene inventario ligado.
                                    </p>
                                </div>
                                <span class="text-xs font-semibold uppercase tracking-[0.14em] text-slate-400" x-text="saboresAbiertos ? 'Ocultar' : 'Mostrar'"></span>
                            </button>

                            <div x-show="saboresAbiertos" x-collapse class="mt-4">

                            <div class="grid gap-3 sm:grid-cols-[1fr_auto]">
                                <div>
                                    <input
                                        type="text"
                                        wire:model.live="quick_flavor_search"
                                        class="w-full rounded-xl border-slate-300 text-sm"
                                        placeholder="helado"
                                    >

                                    @error('quick_flavor_search')
                                        <p class="mt-1 text-sm text-red-600">{{ $message }}</p>
                                    @enderror
                                </div>

                                <button type="button" wire:click="agregarSaboresSeleccionados" class="inline-flex items-center justify-center rounded-xl bg-indigo-600 px-4 py-2 text-sm font-medium text-white transition hover:bg-indigo-700">
                                    Agregar seleccionados
                                </button>
                            </div>

                            @error('selected_flavor_item_ids')
                                <p class="mt-2 text-sm text-red-600">{{ $message }}</p>
                            @enderror

                            <div class="mt-4 max-h-72 overflow-y-auto rounded-2xl border border-slate-200 bg-slate-50">
                                @forelse ($this->flavorInventoryItems as $item)
                                    <label wire:key="flavor-item-{{ $item->id }}" class="flex cursor-pointer items-center justify-between gap-3 border-b border-slate-100 px-4 py-3 last:border-b-0 hover:bg-white">
                                        <div class="min-w-0">
                                            <p class="truncate text-sm font-medium text-slate-900">{{ $item->name }}</p>
                                            <p class="text-xs text-slate-500">
                                                Stock {{ number_format((float) $item->current_stock, 3) }}
                                                {{ $item->unit?->abbreviation ?? 'unidad' }}
                                            </p>
                                            <p class="text-[11px] text-slate-400">
                                                Categoria insumo: {{ $item->legacyInsumo?->categoria?->nombre ?? 'Sin categoria' }}
                                            </p>
                                        </div>

                                        <input
                                            type="checkbox"
                                            wire:model="selected_flavor_item_ids"
                                            value="{{ $item->id }}"
                                            class="h-5 w-5 rounded border-slate-300 text-indigo-600 focus:ring-indigo-500"
                                        >
                                    </label>
                                @empty
                                    <div class="px-4 py-6 text-center text-sm text-slate-500">
                                        No encontre insumos ligados a inventario con ese filtro.
                                    </div>
                                @endforelse
                            </div>
                        </div>

                        </div>

                        <div class="px-5 py-4">
                            <button type="button" x-on:click="gruposAbiertos = ! gruposAbiertos" class="flex w-full items-center justify-between gap-3 text-left">
                                <div>
                                    <h3 class="text-sm font-semibold text-slate-950">3. Revisa y edita grupos existentes</h3>
                                    <p class="text-xs text-slate-500">Ajusta cualquier campo inline: nombre, cantidades, consumo por bola y precio extra.</p>
                                </div>
                                <span class="text-xs font-semibold uppercase tracking-[0.14em] text-slate-400" x-text="gruposAbiertos ? 'Ocultar' : 'Mostrar'"></span>
                            </button>

                            <div x-show="gruposAbiertos" x-collapse class="mt-4 space-y-4">

                            @forelse ($this->productoSeleccionado->productOptionGroups as $group)
                                <div wire:key="grupo-configurable-{{ $group->id }}" class="rounded-2xl border border-indigo-200 bg-white p-4">
                                    <div class="mb-4 flex flex-col gap-3">
                                        <div class="flex flex-col gap-2 sm:flex-row sm:items-center sm:justify-between">
                                            <p class="text-sm font-semibold text-slate-900">Grupo configurable</p>

                                            <button type="button" wire:click="quitarGrupoOpciones({{ $group->id }})" class="text-xs font-medium text-red-600 hover:text-red-700">
                                                Quitar grupo
                                            </button>
                                        </div>

                                        <div class="grid gap-3 lg:grid-cols-[1fr_140px_140px_140px]">
                                            <div>
                                                <label class="mb-1 block text-xs font-medium uppercase tracking-[0.14em] text-slate-500">Nombre</label>
                                                <input type="text" value="{{ $group->name }}" wire:change="actualizarGrupoOpciones({{ $group->id }}, 'name', $event.target.value)" class="w-full rounded-xl border-slate-300 text-sm">
                                            </div>

                                            <div>
                                                <label class="mb-1 block text-xs font-medium uppercase tracking-[0.14em] text-slate-500">Requeridas</label>
                                                <input type="number" step="0.001" value="{{ $group->required_quantity }}" wire:change="actualizarGrupoOpciones({{ $group->id }}, 'required_quantity', $event.target.value)" class="w-full rounded-xl border-slate-300 text-sm">
                                            </div>

                                            <div>
                                                <label class="mb-1 block text-xs font-medium uppercase tracking-[0.14em] text-slate-500">Minimo</label>
                                                <input type="number" step="0.001" value="{{ $group->min_quantity }}" wire:change="actualizarGrupoOpciones({{ $group->id }}, 'min_quantity', $event.target.value)" class="w-full rounded-xl border-slate-300 text-sm">
                                            </div>

                                            <div>
                                                <label class="mb-1 block text-xs font-medium uppercase tracking-[0.14em] text-slate-500">Maximo</label>
                                                <input type="number" step="0.001" value="{{ $group->max_quantity }}" wire:change="actualizarGrupoOpciones({{ $group->id }}, 'max_quantity', $event.target.value)" class="w-full rounded-xl border-slate-300 text-sm">
                                            </div>
                                        </div>
                                    </div>

                                    <form wire:submit="agregarOpcionGrupo({{ $group->id }})" class="grid gap-4 lg:grid-cols-[1fr_160px_160px_auto]">
                                        <div>
                                            <label class="mb-1 block text-sm font-medium text-slate-700">Inventario / sabor</label>
                                            <select wire:model="option_inventory_item_id" class="w-full rounded-xl border-slate-300 text-sm">
                                                <option value="">Selecciona inventario</option>

                                                @foreach ($this->inventoryItems as $item)
                                                    <option value="{{ $item->id }}">
                                                        {{ $item->name }} ({{ $item->unit?->abbreviation ?? 'unidad' }})
                                                    </option>
                                                @endforeach
                                            </select>

                                            @error('option_inventory_item_id')
                                                <p class="mt-1 text-sm text-red-600">{{ $message }}</p>
                                            @enderror
                                        </div>

                                        <div>
                                            <label class="mb-1 block text-sm font-medium text-slate-700">Cantidad por bola / seleccion</label>
                                            <input type="number" step="0.001" wire:model="option_quantity_per_selection" class="w-full rounded-xl border-slate-300 text-sm">
                                            <p class="mt-1 text-xs text-slate-500">Ejemplo: si el insumo esta en litros, una bola puede consumir 0.120.</p>

                                            @error('option_quantity_per_selection')
                                                <p class="mt-1 text-sm text-red-600">{{ $message }}</p>
                                            @enderror
                                        </div>

                                        <div>
                                            <label class="mb-1 block text-sm font-medium text-slate-700">Precio extra</label>
                                            <input type="number" step="0.01" wire:model="option_extra_price" class="w-full rounded-xl border-slate-300 text-sm">
                                        </div>

                                        <div class="flex items-end">
                                            <button type="submit" class="inline-flex items-center rounded-xl bg-slate-900 px-4 py-2 text-sm font-medium text-white transition hover:bg-slate-800">
                                                Agregar opciÃ³n
                                            </button>
                                        </div>
                                    </form>

                                    <div class="mt-4 divide-y divide-slate-100 rounded-xl border border-slate-200 bg-slate-50">
                                        @forelse ($group->optionItems as $option)
                                            <div wire:key="opcion-configurable-{{ $option->id }}" class="grid gap-3 px-4 py-3 lg:grid-cols-[1.2fr_170px_140px_auto]">
                                                <div>
                                                    <label class="mb-1 block text-xs font-medium uppercase tracking-[0.14em] text-slate-500">Sabor</label>
                                                    <select wire:change="actualizarOpcionConfigurable({{ $option->id }}, 'inventory_item_id', $event.target.value)" class="w-full rounded-xl border-slate-300 text-sm">
                                                        @foreach ($this->flavorInventoryItems as $item)
                                                            <option value="{{ $item->id }}" @selected($item->id === $option->inventory_item_id)>
                                                                {{ $item->name }}
                                                            </option>
                                                        @endforeach
                                                    </select>
                                                </div>

                                                <div>
                                                    <label class="mb-1 block text-xs font-medium uppercase tracking-[0.14em] text-slate-500">Por bola</label>
                                                    <input type="number" step="0.001" value="{{ $option->quantity_per_selection }}" wire:change="actualizarOpcionConfigurable({{ $option->id }}, 'quantity_per_selection', $event.target.value)" class="w-full rounded-xl border-slate-300 text-sm">
                                                </div>

                                                <div>
                                                    <label class="mb-1 block text-xs font-medium uppercase tracking-[0.14em] text-slate-500">Extra</label>
                                                    <input type="number" step="0.01" value="{{ $option->extra_price ?? 0 }}" wire:change="actualizarOpcionConfigurable({{ $option->id }}, 'extra_price', $event.target.value)" class="w-full rounded-xl border-slate-300 text-sm">
                                                </div>

                                                <div class="flex items-end">
                                                    <button type="button" wire:click="quitarOpcion({{ $option->id }})" class="text-xs font-medium text-red-600 hover:text-red-700">
                                                        Quitar
                                                    </button>
                                                </div>
                                            </div>
                                        @empty
                                            <div class="px-4 py-5 text-center text-sm text-slate-500">
                                                Este grupo todavÃ­a no tiene opciones.
                                            </div>
                                        @endforelse
                                    </div>
                                </div>
                            @empty
                                <div class="rounded-xl border border-dashed border-indigo-300 bg-white px-4 py-8 text-center text-sm text-indigo-700">
                                    Crea un grupo como "Sabores" para comenzar a configurar opciones.
                                </div>
                            @endforelse
                        </div>
                            </div>
                        </div>
                    </div>
                @endif

                <div class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm">
                    <div class="mb-4">
                        <h2 class="text-lg font-semibold text-slate-900">Agregar insumo a receta</h2>
                        <p class="text-sm text-slate-500">Relaciona insumos activos y define su consumo por unidad vendida.</p>
                    </div>

                    <div class="mb-4">
                        <label class="mb-1 block text-sm font-medium text-slate-700">Buscar insumo</label>
                        <input
                            type="text"
                            wire:model.live="searchInsumo"
                            class="w-full rounded-xl border-slate-300 text-sm"
                            placeholder="Ej. Leche"
                        >
                    </div>

                    <form wire:submit="agregarInsumo" class="grid gap-4 md:grid-cols-[1fr_180px_auto]">
                        <div>
                            <label class="mb-1 block text-sm font-medium text-slate-700">Insumo</label>
                            <select wire:model="insumo_id" class="w-full rounded-xl border-slate-300 text-sm">
                                <option value="">Selecciona un insumo</option>

                                @foreach ($this->insumosDisponibles as $insumo)
                                    <option value="{{ $insumo->id }}">
                                        {{ $insumo->nombre }} ({{ $insumo->unidad_medida }})
                                    </option>
                                @endforeach
                            </select>

                            @error('insumo_id')
                                <p class="mt-1 text-sm text-red-600">{{ $message }}</p>
                            @enderror
                        </div>

                        <div>
                            <label class="mb-1 block text-sm font-medium text-slate-700">Cantidad requerida</label>
                            <input
                                type="number"
                                step="0.001"
                                wire:model="cantidad_requerida"
                                class="w-full rounded-xl border-slate-300 text-sm"
                                placeholder="0.120"
                            >

                            @error('cantidad_requerida')
                                <p class="mt-1 text-sm text-red-600">{{ $message }}</p>
                            @enderror
                        </div>

                        <div class="flex items-end">
                            <button
                                type="submit"
                                class="inline-flex items-center rounded-xl bg-slate-900 px-4 py-2 text-sm font-medium text-white transition hover:bg-slate-800"
                            >
                                Agregar
                            </button>
                        </div>
                    </form>
                </div>
                @if ($product_type !== 'configurable')
                <div class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm">
                    <div class="mb-4">
                        <h2 class="text-lg font-semibold text-slate-900">Receta actual</h2>
                        <p class="text-sm text-slate-500">Ajusta consumos y observa el costo estimado actualizado.</p>
                    </div>

                    @if ($this->productoSeleccionado->insumos->isEmpty())
                        <div class="rounded-xl border border-dashed border-slate-300 bg-slate-50 px-4 py-8 text-center text-sm text-slate-500">
                            Este producto todavía no tiene receta.
                        </div>
                    @else
                        <div class="overflow-x-auto">
                            <table class="min-w-full border-collapse text-left text-sm">
                                <thead>
                                    <tr class="border-b border-slate-200 bg-slate-50 text-slate-600">
                                        <th class="p-3 font-medium">Insumo</th>
                                        <th class="p-3 font-medium">Categoría</th>
                                        <th class="p-3 font-medium">Unidad</th>
                                        <th class="p-3 font-medium">Cantidad requerida</th>
                                        <th class="p-3 font-medium">Costo unitario</th>
                                        <th class="p-3 font-medium">Costo calculado</th>
                                        <th class="p-3 font-medium">Acciones</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    @foreach ($this->productoSeleccionado->insumos as $insumo)
                                        <tr wire:key="receta-{{ $this->productoSeleccionado->id }}-{{ $insumo->id }}" class="border-b border-slate-100 align-top">
                                            <td class="p-3 font-medium text-slate-900">{{ $insumo->nombre }}</td>
                                            <td class="p-3 text-slate-700">{{ $insumo->categoria?->nombre ?? 'Sin categoría' }}</td>
                                            <td class="p-3 text-slate-700">{{ $insumo->unidad_medida }}</td>
                                            <td class="p-3">
                                                <input
                                                    type="number"
                                                    step="0.001"
                                                    value="{{ $insumo->pivot->cantidad_requerida }}"
                                                    wire:change="actualizarCantidad({{ $insumo->id }}, $event.target.value)"
                                                    class="w-32 rounded-xl border-slate-300 text-sm"
                                                >
                                            </td>
                                            <td class="p-3 text-slate-700">${{ number_format((float) $insumo->costo_unitario, 2) }}</td>
                                            <td class="p-3 font-medium text-slate-900">
                                                ${{ number_format((float) $insumo->pivot->cantidad_requerida * (float) $insumo->costo_unitario, 2) }}
                                            </td>
                                            <td class="p-3">
                                                <button
                                                    type="button"
                                                    wire:click="quitarInsumo({{ $insumo->id }})"
                                                    class="text-xs font-medium text-red-600 hover:text-red-700"
                                                >
                                                    Quitar
                                                </button>
                                            </td>
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        </div>
                    @endif
                </div>
                @endif
            @endif
        </div>
    </div>
</div>

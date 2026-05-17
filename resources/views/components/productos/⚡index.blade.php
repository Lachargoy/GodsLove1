<?php

use App\Models\CategoriaProducto;
use App\Models\InventoryItem;
use App\Models\Insumo;
use App\Models\Producto;
use App\Models\ProductOptionGroup;
use App\Models\ProductRecipe;
use App\Models\ProductoInsumo;
use Livewire\Component;

new class extends Component
{
    public string $search = '';
    public string $filtro_categoria_producto_id = '';
    public string $editing_producto_id = '';
    public string $categoria_producto_id = '';
    public string $nombre = '';
    public string $descripcion = '';
    public string $precio_venta = '';
    public string $costo_estimado = '';
    public string $product_type = 'prepared';
    public string $inventory_item_id = '';
    public string $option_group_name = 'Sabores';
    public string $required_quantity = '2';
    public array $receta = [
        [
            'insumo_id' => '',
            'cantidad_requerida' => '',
        ],
    ];

    public function getCategoriasProperty()
    {
        return CategoriaProducto::query()
            ->where('activo', true)
            ->orderBy('nombre')
            ->get();
    }

    public function getInsumosActivosProperty()
    {
        return Insumo::query()
            ->where('activo', true)
            ->orderBy('nombre')
            ->get();
    }

    public function getInventoryItemsProperty()
    {
        return InventoryItem::query()
            ->with('unit')
            ->where('is_active', true)
            ->orderBy('name')
            ->get();
    }

    public function getProductosProperty()
    {
        return Producto::query()
            ->with('categoria')
            ->when($this->search !== '', function ($query) {
                $query->where('nombre', 'like', '%'.trim($this->search).'%');
            })
            ->when($this->filtro_categoria_producto_id !== '', function ($query) {
                $query->where('categoria_producto_id', $this->filtro_categoria_producto_id);
            })
            ->orderByDesc('created_at')
            ->get();
    }

    public function getTotalProductosProperty(): int
    {
        return Producto::query()->count();
    }

    public function getProductosActivosProperty(): int
    {
        return Producto::query()
            ->where('activo', true)
            ->count();
    }

    public function getProductosInactivosProperty(): int
    {
        return Producto::query()
            ->where('activo', false)
            ->count();
    }

    public function agregarLineaReceta(): void
    {
        $this->receta[] = [
            'insumo_id' => '',
            'cantidad_requerida' => '',
        ];
    }

    public function quitarLineaReceta(int $index): void
    {
        unset($this->receta[$index]);
        $this->receta = array_values($this->receta);

        if ($this->receta === []) {
            $this->agregarLineaReceta();
        }
    }

    public function updatedProductType(string $value): void
    {
        if ($value !== 'simple') {
            $this->inventory_item_id = '';
        }

        if ($value !== 'configurable') {
            $this->option_group_name = 'Sabores';
            $this->required_quantity = '2';
        }

        $this->resetErrorBag([
            'inventory_item_id',
            'option_group_name',
            'required_quantity',
            'receta',
        ]);
    }

    public function guardar(): void
    {
        $validated = $this->validate([
            'categoria_producto_id' => ['nullable', 'exists:categoria_productos,id'],
            'nombre' => ['required', 'string', 'max:255'],
            'descripcion' => ['nullable', 'string'],
            'precio_venta' => ['required', 'numeric', 'min:0'],
            'costo_estimado' => ['nullable', 'numeric', 'min:0'],
            'product_type' => ['required', 'in:simple,prepared,configurable'],
            'inventory_item_id' => ['nullable', 'exists:inventory_items,id'],
            'option_group_name' => ['nullable', 'string', 'max:255'],
            'required_quantity' => ['nullable', 'numeric', 'min:0.001'],
            'receta.*.insumo_id' => ['nullable', 'exists:insumos,id'],
            'receta.*.cantidad_requerida' => ['nullable', 'numeric', 'min:0.001'],
        ]);

        if ($validated['product_type'] === 'simple' && blank($validated['inventory_item_id'])) {
            $this->addError('inventory_item_id', 'Selecciona el inventario que descuenta este producto simple.');

            return;
        }

        if ($validated['product_type'] === 'configurable' && blank($validated['option_group_name'])) {
            $this->addError('option_group_name', 'Define el nombre del grupo configurable.');

            return;
        }

        $recetaInicial = collect($validated['receta'] ?? [])
            ->filter(fn (array $item) => filled($item['insumo_id'] ?? null) || filled($item['cantidad_requerida'] ?? null))
            ->values();

        $insumosDuplicados = $recetaInicial
            ->pluck('insumo_id')
            ->filter()
            ->duplicates();

        if ($insumosDuplicados->isNotEmpty()) {
            $this->addError('receta', 'No puedes repetir el mismo insumo en la receta inicial.');

            return;
        }

        $isEditing = $this->editing_producto_id !== '';

        $producto = $isEditing
            ? Producto::query()->findOrFail($this->editing_producto_id)
            : new Producto(['activo' => true]);

        $producto->fill([
            'categoria_producto_id' => $validated['categoria_producto_id'] ?: null,
            'nombre' => trim($validated['nombre']),
            'descripcion' => $validated['descripcion'] ?: null,
            'precio_venta' => $validated['precio_venta'],
            'costo_estimado' => $validated['costo_estimado'] ?: 0,
            'product_type' => $validated['product_type'],
            'inventory_item_id' => $validated['product_type'] === 'simple' ? $validated['inventory_item_id'] : null,
        ])->save();

        if ($validated['product_type'] === 'simple') {
            InventoryItem::query()
                ->whereKey($validated['inventory_item_id'])
                ->update(['is_sellable' => true]);
        }

        if ($validated['product_type'] === 'configurable') {
            $groupAttributes = [
                'name' => trim($validated['option_group_name'] ?: 'Sabores'),
                'required_quantity' => (float) ($validated['required_quantity'] ?: 2),
                'min_quantity' => (float) ($validated['required_quantity'] ?: 2),
                'max_quantity' => (float) ($validated['required_quantity'] ?: 2),
            ];

            $optionGroup = $producto->productOptionGroups()->first();

            if ($optionGroup) {
                $optionGroup->update($groupAttributes);
            } else {
                $producto->productOptionGroups()->create($groupAttributes);
            }
        }

        if ($recetaInicial->isNotEmpty()) {
            $costoCalculado = 0.0;

            foreach ($recetaInicial as $item) {
                if (blank($item['insumo_id'] ?? null) || blank($item['cantidad_requerida'] ?? null)) {
                    $this->addError('receta', 'Cada linea de receta debe incluir insumo y cantidad requerida.');

                    if (! $isEditing) {
                        $producto->delete();
                    }

                    return;
                }

                $insumo = Insumo::query()->findOrFail($item['insumo_id']);
                $cantidadRequerida = (float) $item['cantidad_requerida'];

                ProductoInsumo::query()->updateOrCreate(
                    [
                        'producto_id' => $producto->id,
                        'insumo_id' => $insumo->id,
                    ],
                    [
                        'cantidad_requerida' => $cantidadRequerida,
                    ],
                );

                if ($insumo->inventory_item_id) {
                    ProductRecipe::query()->updateOrCreate(
                        [
                            'product_id' => $producto->id,
                            'inventory_item_id' => $insumo->inventory_item_id,
                        ],
                        [
                            'quantity' => $cantidadRequerida,
                        ],
                    );
                }

                $costoCalculado += $cantidadRequerida * (float) $insumo->costo_unitario;
            }

            $producto->update([
                'costo_estimado' => round($costoCalculado, 2),
            ]);
        }

        $message = $isEditing
            ? 'Producto actualizado correctamente.'
            : 'Producto creado correctamente.';

        $this->resetProductForm();

        session()->flash('success', $message);
    }

    public function editarProducto(int $productoId): void
    {
        $producto = Producto::query()->findOrFail($productoId);

        $this->editing_producto_id = (string) $producto->id;
        $this->categoria_producto_id = (string) ($producto->categoria_producto_id ?? '');
        $this->nombre = $producto->nombre;
        $this->descripcion = $producto->descripcion ?? '';
        $this->precio_venta = (string) $producto->precio_venta;
        $this->costo_estimado = (string) $producto->costo_estimado;
        $this->product_type = $producto->product_type;
        $this->inventory_item_id = (string) ($producto->inventory_item_id ?? '');
        $this->option_group_name = $producto->productOptionGroups()->first()?->name ?? 'Sabores';
        $this->required_quantity = (string) ($producto->productOptionGroups()->first()?->required_quantity ?? 2);
        $this->receta = [
            [
                'insumo_id' => '',
                'cantidad_requerida' => '',
            ],
        ];

        $this->resetErrorBag();
    }

    public function cancelarEdicion(): void
    {
        $this->resetProductForm();
    }

    public function toggleActivo(int $productoId): void
    {
        $producto = Producto::query()->findOrFail($productoId);

        $producto->update([
            'activo' => ! $producto->activo,
        ]);

        session()->flash(
            'success',
            $producto->fresh()->activo
                ? 'Producto activado correctamente.'
                : 'Producto desactivado correctamente.',
        );
    }

    public function limpiarFiltros(): void
    {
        $this->reset([
            'search',
            'filtro_categoria_producto_id',
        ]);
    }

    private function resetProductForm(): void
    {
        $this->reset([
            'editing_producto_id',
            'categoria_producto_id',
            'nombre',
            'descripcion',
            'precio_venta',
            'costo_estimado',
            'product_type',
            'inventory_item_id',
            'option_group_name',
            'required_quantity',
        ]);

        $this->product_type = 'prepared';
        $this->option_group_name = 'Sabores';
        $this->required_quantity = '2';
        $this->receta = [
            [
                'insumo_id' => '',
                'cantidad_requerida' => '',
            ],
        ];

        $this->resetErrorBag();
    }
};
?>

<div class="space-y-6">
    <div class="app-hero flex flex-col gap-4 lg:flex-row lg:items-end lg:justify-between">
        <div>
            <p class="text-xs font-semibold uppercase tracking-[0.24em] text-sky-700">Catalogo</p>
            <h1 class="app-page-title">Productos</h1>
            <p class="app-page-copy">
                Administra los productos que vendes en la heladeria con una interfaz mas clara para recetas,
                inventario directo y configuraciones tipo helado doble.
            </p>
        </div>

        <div class="flex flex-wrap gap-3">
            <a href="{{ route('categorias.index') }}" wire:navigate class="app-secondary-button">
                Administrar categorias
            </a>
            <a href="{{ route('productos.recetas') }}" wire:navigate class="app-primary-button">
                Editar receta
            </a>
        </div>
    </div>

    <div class="grid gap-4 md:grid-cols-3">
        <div class="app-stat-card">
            <p class="text-sm text-slate-500">Total productos</p>
            <p class="mt-2 text-3xl font-semibold text-slate-900">{{ $this->totalProductos }}</p>
        </div>

        <div class="app-stat-card border-emerald-200 bg-emerald-50/90">
            <p class="text-sm text-emerald-700">Activos</p>
            <p class="mt-2 text-3xl font-semibold text-emerald-900">{{ $this->productosActivos }}</p>
        </div>

        <div class="app-stat-card bg-slate-50/90">
            <p class="text-sm text-slate-600">Inactivos</p>
            <p class="mt-2 text-3xl font-semibold text-slate-900">{{ $this->productosInactivos }}</p>
        </div>
    </div>

    <div class="grid gap-6 xl:grid-cols-[1.35fr_0.65fr]">
        <div class="app-card">
            <div class="mb-6 flex flex-col gap-2 md:flex-row md:items-end md:justify-between">
                <div>
                    <h2 class="text-xl font-semibold text-slate-950">{{ $editing_producto_id !== '' ? 'Editar producto' : 'Nuevo producto' }}</h2>
                    <p class="mt-1 text-sm text-slate-500">
                        {{ $editing_producto_id !== '' ? 'Ajusta nombre, categoria, precio, costo y tipo del producto seleccionado.' : 'Registra un producto nuevo y elige una estructura mas clara segun como se vende y descuenta inventario.' }}
                    </p>
                </div>

                @if ($editing_producto_id !== '')
                    <button
                        type="button"
                        wire:click="cancelarEdicion"
                        class="inline-flex items-center rounded-xl border border-slate-300 bg-white px-3 py-2 text-xs font-medium text-slate-700 transition hover:bg-slate-50"
                    >
                        Cancelar edicion
                    </button>
                @else
                    <div class="rounded-2xl border border-slate-200 bg-slate-50 px-3 py-2 text-xs text-slate-500">
                        Flujo guiado para evitar errores visuales
                    </div>
                @endif
            </div>

            <form wire:submit="guardar" class="space-y-6">
                <section class="grid gap-4 lg:grid-cols-2">
                    <div>
                        <label class="mb-1 block text-sm font-medium text-slate-700">Categoria</label>
                        <select wire:model="categoria_producto_id" class="w-full rounded-2xl border-slate-300 bg-white text-sm">
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
                        <label class="mb-1 block text-sm font-medium text-slate-700">Nombre</label>
                        <input
                            type="text"
                            wire:model="nombre"
                            class="w-full rounded-2xl border-slate-300 bg-white text-sm"
                            placeholder="Ej. Cono sencillo"
                        >

                        @error('nombre')
                            <p class="mt-1 text-sm text-red-600">{{ $message }}</p>
                        @enderror
                    </div>

                    <div>
                        <label class="mb-1 block text-sm font-medium text-slate-700">Precio de venta</label>
                        <input
                            type="number"
                            step="0.01"
                            wire:model="precio_venta"
                            class="w-full rounded-2xl border-slate-300 bg-white text-sm"
                            placeholder="30.00"
                        >

                        @error('precio_venta')
                            <p class="mt-1 text-sm text-red-600">{{ $message }}</p>
                        @enderror
                    </div>

                    <div>
                        <label class="mb-1 block text-sm font-medium text-slate-700">Costo estimado</label>
                        <input
                            type="number"
                            step="0.01"
                            wire:model="costo_estimado"
                            class="w-full rounded-2xl border-slate-300 bg-white text-sm"
                            placeholder="12.00"
                        >

                        @error('costo_estimado')
                            <p class="mt-1 text-sm text-red-600">{{ $message }}</p>
                        @enderror
                    </div>

                    <div class="lg:col-span-2">
                        <label class="mb-1 block text-sm font-medium text-slate-700">Descripcion</label>
                        <textarea
                            wire:model="descripcion"
                            class="w-full rounded-2xl border-slate-300 bg-white text-sm"
                            rows="3"
                            placeholder="Descripcion opcional"
                        ></textarea>

                        @error('descripcion')
                            <p class="mt-1 text-sm text-red-600">{{ $message }}</p>
                        @enderror
                    </div>
                </section>

                <section class="space-y-4">
                    <div>
                        <h3 class="text-sm font-semibold text-slate-950">Tipo de producto</h3>
                        <p class="text-xs text-slate-500">Selecciona la logica de descuento y venta que quieres usar.</p>
                    </div>

                    <div class="grid gap-3 lg:grid-cols-3">
                        <label class="block cursor-pointer">
                            <input
                                type="radio"
                                name="product_type"
                                value="prepared"
                                wire:model.live="product_type"
                                class="sr-only"
                            >

                            <div class="rounded-[22px] border p-4 text-left transition {{ $product_type === 'prepared' ? 'border-slate-900 bg-slate-950 text-white shadow-[0_18px_40px_-28px_rgba(15,23,42,0.65)]' : 'border-slate-200 bg-white text-slate-900 hover:border-slate-300 hover:bg-slate-50' }}">
                                <p class="text-sm font-semibold">Preparado con receta</p>
                                <p class="mt-2 text-xs leading-6 {{ $product_type === 'prepared' ? 'text-slate-200' : 'text-slate-500' }}">
                                    Usa insumos fijos por cada venta. Ideal para conos, malteadas o productos con receta estable.
                                </p>
                            </div>
                        </label>

                        <label class="block cursor-pointer">
                            <input
                                type="radio"
                                name="product_type"
                                value="simple"
                                wire:model.live="product_type"
                                class="sr-only"
                            >

                            <div class="rounded-[22px] border p-4 text-left transition {{ $product_type === 'simple' ? 'border-emerald-600 bg-emerald-600 text-white shadow-[0_18px_40px_-28px_rgba(5,150,105,0.6)]' : 'border-slate-200 bg-white text-slate-900 hover:border-emerald-200 hover:bg-emerald-50/40' }}">
                                <p class="text-sm font-semibold">Simple: descuenta directo</p>
                                <p class="mt-2 text-xs leading-6 {{ $product_type === 'simple' ? 'text-emerald-50' : 'text-slate-500' }}">
                                    El producto vende una sola pieza de inventario. Ideal para paletas o articulos unitarios.
                                </p>
                            </div>
                        </label>

                        <label class="block cursor-pointer">
                            <input
                                type="radio"
                                name="product_type"
                                value="configurable"
                                wire:model.live="product_type"
                                class="sr-only"
                            >

                            <div class="rounded-[22px] border p-4 text-left transition {{ $product_type === 'configurable' ? 'border-indigo-600 bg-indigo-600 text-white shadow-[0_18px_40px_-28px_rgba(79,70,229,0.6)]' : 'border-slate-200 bg-white text-slate-900 hover:border-indigo-200 hover:bg-indigo-50/50' }}">
                                <p class="text-sm font-semibold">Configurable</p>
                                <p class="mt-2 text-xs leading-6 {{ $product_type === 'configurable' ? 'text-indigo-50' : 'text-slate-500' }}">
                                    El cliente elige opciones al vender. Ideal para un helado doble con dos sabores.
                                </p>
                            </div>
                        </label>
                    </div>

                    @error('product_type')
                        <p class="text-sm text-red-600">{{ $message }}</p>
                    @enderror
                </section>

                @if ($product_type === 'simple')
                    <section wire:key="product-type-simple" class="rounded-[26px] border border-emerald-200 bg-emerald-50/80 p-5">
                        <div class="mb-4">
                            <h3 class="text-sm font-semibold text-slate-950">Producto simple</h3>
                            <p class="mt-1 text-xs text-slate-600">Conecta el producto a una sola referencia de inventario.</p>
                        </div>

                        <label class="mb-1 block text-sm font-medium text-slate-700">Inventario directo</label>
                        <select wire:model="inventory_item_id" class="w-full rounded-2xl border-slate-300 bg-white text-sm">
                            <option value="">Selecciona inventario</option>

                            @foreach ($this->inventoryItems as $item)
                                <option value="{{ $item->id }}">
                                    {{ $item->name }} ({{ $item->unit?->abbreviation ?? 'unidad' }})
                                </option>
                            @endforeach
                        </select>

                        @error('inventory_item_id')
                            <p class="mt-1 text-sm text-red-600">{{ $message }}</p>
                        @enderror
                    </section>
                @endif

                @if ($product_type === 'configurable')
                    <section wire:key="product-type-configurable" class="rounded-[26px] border border-indigo-200 bg-indigo-50/80 p-5 shadow-[0_18px_35px_-30px_rgba(79,70,229,0.45)]">
                        <div class="mb-5 flex flex-col gap-2 lg:flex-row lg:items-end lg:justify-between">
                            <div>
                                <h3 class="text-sm font-semibold text-slate-950">Producto configurable</h3>
                                <p class="mt-1 text-xs text-slate-600">Configura el primer grupo de opciones para que quede listo para vender.</p>
                            </div>

                            <div class="rounded-2xl border border-indigo-200 bg-white px-3 py-2 text-xs text-indigo-700">
                                Patron recomendado para helado doble
                            </div>
                        </div>

                        <div class="grid gap-4 lg:grid-cols-2">
                            <div>
                                <label class="mb-1 block text-sm font-medium text-slate-700">Grupo configurable</label>
                                <input
                                    type="text"
                                    wire:model.live="option_group_name"
                                    class="w-full rounded-2xl border-slate-300 bg-white text-sm"
                                    placeholder="Sabores"
                                >

                                @error('option_group_name')
                                    <p class="mt-1 text-sm text-red-600">{{ $message }}</p>
                                @enderror
                            </div>

                            <div>
                                <label class="mb-1 block text-sm font-medium text-slate-700">Selecciones requeridas</label>
                                <input
                                    type="number"
                                    step="0.001"
                                    min="1"
                                    wire:model.live="required_quantity"
                                    class="w-full rounded-2xl border-slate-300 bg-white text-sm"
                                    placeholder="2"
                                >

                                @error('required_quantity')
                                    <p class="mt-1 text-sm text-red-600">{{ $message }}</p>
                                @enderror
                            </div>
                        </div>

                        <div class="mt-4 grid gap-3 md:grid-cols-3">
                            <div class="rounded-2xl border border-white/80 bg-white/90 p-4">
                                <p class="text-xs uppercase tracking-[0.16em] text-slate-400">Ejemplo</p>
                                <p class="mt-2 text-sm font-semibold text-slate-900">Helado doble</p>
                            </div>
                            <div class="rounded-2xl border border-white/80 bg-white/90 p-4">
                                <p class="text-xs uppercase tracking-[0.16em] text-slate-400">Grupo</p>
                                <p class="mt-2 text-sm font-semibold text-slate-900">{{ $option_group_name !== '' ? $option_group_name : 'Sabores' }}</p>
                            </div>
                            <div class="rounded-2xl border border-white/80 bg-white/90 p-4">
                                <p class="text-xs uppercase tracking-[0.16em] text-slate-400">Cantidad</p>
                                <p class="mt-2 text-sm font-semibold text-slate-900">{{ $required_quantity !== '' ? $required_quantity : '2' }} selecciones</p>
                            </div>
                        </div>
                    </section>
                @endif

                @if ($product_type !== 'configurable')
                    <section wire:key="product-recipe-section-{{ $product_type }}" class="app-card-muted">
                        <div class="mb-4 flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
                            <div>
                                <h3 class="text-sm font-semibold text-slate-900">Receta inicial opcional</h3>
                                <p class="text-xs text-slate-500">Agrega insumos desde el alta si ya conoces la receta.</p>
                            </div>

                            <button
                                type="button"
                                wire:click="agregarLineaReceta"
                                class="inline-flex items-center rounded-xl border border-slate-300 bg-white px-3 py-1.5 text-xs font-medium text-slate-700 transition hover:bg-slate-50"
                            >
                                Agregar insumo
                            </button>
                        </div>

                        @error('receta')
                            <p class="mb-3 text-sm text-red-600">{{ $message }}</p>
                        @enderror

                        <div class="space-y-3">
                            @foreach ($receta as $index => $item)
                                <div wire:key="receta-linea-{{ $index }}" class="grid gap-3 rounded-2xl border border-slate-200 bg-white p-3 md:grid-cols-[1fr_180px_auto]">
                                    <div>
                                        <label class="mb-1 block text-xs font-medium text-slate-600">Insumo</label>
                                        <select wire:model="receta.{{ $index }}.insumo_id" class="w-full rounded-xl border-slate-300 bg-white text-sm">
                                            <option value="">Selecciona un insumo</option>

                                            @foreach ($this->insumosActivos as $insumo)
                                                <option value="{{ $insumo->id }}">
                                                    {{ $insumo->nombre }} ({{ $insumo->unidad_medida }})
                                                </option>
                                            @endforeach
                                        </select>

                                        @error("receta.$index.insumo_id")
                                            <p class="mt-1 text-sm text-red-600">{{ $message }}</p>
                                        @enderror
                                    </div>

                                    <div>
                                        <label class="mb-1 block text-xs font-medium text-slate-600">Cantidad requerida</label>
                                        <input
                                            type="number"
                                            step="0.001"
                                            wire:model="receta.{{ $index }}.cantidad_requerida"
                                            class="w-full rounded-xl border-slate-300 bg-white text-sm"
                                            placeholder="0.120"
                                        >

                                        @error("receta.$index.cantidad_requerida")
                                            <p class="mt-1 text-sm text-red-600">{{ $message }}</p>
                                        @enderror
                                    </div>

                                    <div class="flex items-end">
                                        <button
                                            type="button"
                                            wire:click="quitarLineaReceta({{ $index }})"
                                            class="inline-flex items-center rounded-xl border border-red-200 px-3 py-2 text-xs font-medium text-red-600 transition hover:bg-red-50"
                                        >
                                            Quitar
                                        </button>
                                    </div>
                                </div>
                            @endforeach
                        </div>
                    </section>
                @endif

                <div class="flex flex-wrap items-center gap-3">
                    <button type="submit" class="app-primary-button">
                        {{ $editing_producto_id !== '' ? 'Actualizar producto' : 'Guardar producto' }}
                    </button>

                    <p class="text-xs text-slate-500">
                        {{ $product_type === 'configurable' ? 'Se creara listo para elegir opciones en venta.' : 'Puedes completar o ajustar la receta despues.' }}
                    </p>
                </div>
            </form>
        </div>

        <div class="space-y-6">
            <div class="app-card">
                <div class="mb-4">
                    <h2 class="text-lg font-semibold text-slate-900">Resumen del tipo</h2>
                    <p class="text-sm text-slate-500">Una referencia visual mas clara del flujo que elegiste.</p>
                </div>

                <div class="space-y-3">
                    <div class="rounded-2xl border px-4 py-4 {{ $product_type === 'prepared' ? 'border-slate-900 bg-slate-950 text-white' : 'border-slate-200 bg-white text-slate-900' }}">
                        <p class="text-sm font-semibold">Preparado con receta</p>
                        <p class="mt-2 text-xs leading-6 {{ $product_type === 'prepared' ? 'text-slate-200' : 'text-slate-500' }}">
                            Usa insumos fijos y funciona bien para la mayor parte del menu tradicional.
                        </p>
                    </div>

                    <div class="rounded-2xl border px-4 py-4 {{ $product_type === 'simple' ? 'border-emerald-600 bg-emerald-600 text-white' : 'border-slate-200 bg-white text-slate-900' }}">
                        <p class="text-sm font-semibold">Simple</p>
                        <p class="mt-2 text-xs leading-6 {{ $product_type === 'simple' ? 'text-emerald-50' : 'text-slate-500' }}">
                            Descuenta una sola pieza de inventario, ideal para articulos unitarios.
                        </p>
                    </div>

                    <div class="rounded-2xl border px-4 py-4 {{ $product_type === 'configurable' ? 'border-indigo-600 bg-indigo-600 text-white' : 'border-slate-200 bg-white text-slate-900' }}">
                        <p class="text-sm font-semibold">Configurable</p>
                        <p class="mt-2 text-xs leading-6 {{ $product_type === 'configurable' ? 'text-indigo-50' : 'text-slate-500' }}">
                            Permite seleccionar opciones al vender. Perfecto para sabores, toppings o combinaciones.
                        </p>
                    </div>
                </div>
            </div>

            <div class="app-card">
                <div class="mb-4">
                    <h2 class="text-lg font-semibold text-slate-900">Filtros</h2>
                    <p class="text-sm text-slate-500">Busca y segmenta tus productos activos o inactivos.</p>
                </div>

                <div class="space-y-4">
                    <div>
                        <label class="mb-1 block text-sm font-medium text-slate-700">Buscar por nombre</label>
                        <input
                            type="text"
                            wire:model.live="search"
                            class="w-full rounded-2xl border-slate-300 bg-white text-sm"
                            placeholder="Ej. Malteada"
                        >
                    </div>

                    <div>
                        <label class="mb-1 block text-sm font-medium text-slate-700">Filtrar por categoria</label>
                        <select wire:model.live="filtro_categoria_producto_id" class="w-full rounded-2xl border-slate-300 bg-white text-sm">
                            <option value="">Todas las categorias</option>

                            @foreach ($this->categorias as $categoria)
                                <option value="{{ $categoria->id }}">{{ $categoria->nombre }}</option>
                            @endforeach
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
    </div>

    <div class="app-card">
        <div class="mb-4 flex items-center justify-between gap-3">
            <div>
                <h2 class="text-lg font-semibold text-slate-900">Lista de productos</h2>
                <p class="text-sm text-slate-500">Consulta precios, costos y estado actual del catalogo.</p>
            </div>
        </div>

        <div class="app-table-wrap">
            <table class="app-table">
                <thead>
                    <tr>
                        <th class="p-3 font-medium">Producto</th>
                        <th class="p-3 font-medium">Categoria</th>
                        <th class="p-3 font-medium">Precio</th>
                        <th class="p-3 font-medium">Costo</th>
                        <th class="p-3 font-medium">Margen</th>
                        <th class="p-3 font-medium">Estado</th>
                        <th class="p-3 font-medium">Acciones</th>
                    </tr>
                </thead>

                <tbody>
                    @forelse ($this->productos as $producto)
                        <tr wire:key="producto-{{ $producto->id }}">
                            <td class="p-3">
                                <div class="font-medium text-slate-900">{{ $producto->nombre }}</div>
                                <div class="text-xs text-slate-500">{{ $producto->descripcion ?: 'Sin descripcion' }}</div>
                            </td>

                            <td class="p-3 text-slate-700">
                                {{ $producto->categoria?->nombre ?? 'Sin categoria' }}
                            </td>

                            <td class="p-3 text-slate-700">
                                ${{ number_format((float) $producto->precio_venta, 2) }}
                            </td>

                            <td class="p-3 text-slate-700">
                                ${{ number_format((float) $producto->costo_estimado, 2) }}
                            </td>

                            <td class="p-3 font-medium text-slate-900">
                                ${{ number_format((float) $producto->precio_venta - (float) $producto->costo_estimado, 2) }}
                            </td>

                            <td class="p-3">
                                @if ($producto->activo)
                                    <span class="rounded-full bg-emerald-100 px-2.5 py-1 text-xs font-medium text-emerald-700">
                                        Activo
                                    </span>
                                @else
                                    <span class="rounded-full bg-slate-200 px-2.5 py-1 text-xs font-medium text-slate-700">
                                        Inactivo
                                    </span>
                                @endif
                            </td>

                            <td class="p-3">
                                <div class="flex flex-col items-start gap-2">
                                    <a
                                        href="{{ route('productos.recetas', ['producto' => $producto->id]) }}"
                                        class="inline-flex items-center rounded-xl border border-sky-200 px-3 py-1.5 text-xs font-medium text-sky-700 transition hover:bg-sky-50"
                                    >
                                        Editar receta
                                    </a>

                                    <button
                                        type="button"
                                        wire:click="editarProducto({{ $producto->id }})"
                                        class="inline-flex items-center rounded-xl border border-amber-200 px-3 py-1.5 text-xs font-medium text-amber-700 transition hover:bg-amber-50"
                                    >
                                        Editar producto
                                    </button>

                                    <button
                                        type="button"
                                        wire:click="toggleActivo({{ $producto->id }})"
                                        class="inline-flex items-center rounded-xl border border-slate-300 px-3 py-1.5 text-xs font-medium text-slate-700 transition hover:bg-slate-50"
                                    >
                                        {{ $producto->activo ? 'Desactivar' : 'Activar' }}
                                    </button>
                                </div>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="7" class="p-4">
                                <div class="godslove-empty">
                                    <p class="godslove-empty-title">No hay productos con esos filtros</p>
                                    <p class="godslove-empty-copy">Limpia la busqueda o registra un producto nuevo para verlo aqui.</p>
                                </div>
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>
</div>

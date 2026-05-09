<?php


use App\Models\CategoriaProducto;
use App\Models\CorteCaja;
use App\Models\Insumo;
use App\Models\MovimientoInventario;
use App\Models\Producto;
use App\Models\Venta;
use App\Models\VentaDetalle;
use App\Services\ProductConfigurationService;
use App\Services\VentaService;
use Illuminate\Support\Facades\DB;
use Livewire\Component;

new class extends Component
{
    public string $search = '';
    public string $categoria_producto_id = '';
    public array $carrito = [];
    public string $descuento = '0';
    public string $monto_recibido = '';
    public string $metodo_pago = 'efectivo';
    public string $venta_seleccionada_id = '';
    public string $configuring_product_id = '';
    public array $selected_options = [];

    public function getProductosProperty()
    {
        return Producto::query()
            ->with([
                'categoria',
                'productOptionGroups.optionItems.inventoryItem.unit',
            ])
            ->where('activo', true)
            ->when($this->search !== '', function ($query) {
                $query->where('nombre', 'like', '%'.trim($this->search).'%');
            })
            ->when($this->categoria_producto_id !== '', function ($query) {
                $query->where('categoria_producto_id', $this->categoria_producto_id);
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

    public function getCajaAbiertaProperty(): ?CorteCaja
    {
        return CorteCaja::query()
            ->abiertaDelDia()
            ->latest('fecha_apertura')
            ->first();
    }

    public function limpiarMenuVenta(): void
    {
        $this->reset([
            'search',
            'categoria_producto_id',
        ]);
    }

    public function getProductoConfigurableProperty(): ?Producto
    {
        if ($this->configuring_product_id === '') {
            return null;
        }

        return Producto::query()
            ->with('productOptionGroups.optionItems.inventoryItem.unit')
            ->where('activo', true)
            ->find($this->configuring_product_id);
    }

    public function getSubtotalProperty(): float
    {
        return round(
            collect($this->carrito)->sum(
                fn (array $item) => (float) $item['precio_unitario'] * (float) $item['cantidad']
            ),
            2,
        );
    }

    public function getTotalProperty(): float
    {
        return max(0, round($this->subtotal - (float) $this->descuento, 2));
    }

    public function getCantidadItemsProperty(): int
    {
        return (int) collect($this->carrito)->sum(fn (array $item) => (int) $item['cantidad']);
    }

    public function getCambioProperty(): float
    {
        if ($this->montoRecibido <= 0) {
            return 0;
        }
        return round(max(0, $this->montoRecibido - $this->total), 2);
    }

    public function getMontoFaltanteProperty(): float
    {
        if ($this->montoRecibido <= 0) {
            return $this->total;
        }
        return round(max(0, $this->total - $this->montoRecibido), 2);
    }

    public function getMontoRecibidoProperty(): float
    {
        return round((float) $this->monto_recibido, 2);
    }

    public function getVentasHoyProperty()
    {
        [$inicio, $fin] = $this->rangoHoy();
        return Venta::query()
            ->with('detalles.producto')
            ->where('estado', 'pagada')
            ->whereBetween('fecha_venta', [$inicio, $fin])
            ->get();
    }

    public function getTotalVendidoHoyProperty(): float
    {
        return round((float) $this->ventasHoy->sum('total'), 2);
    }

    public function getTicketsHoyProperty(): int
    {
        return $this->ventasHoy->count();
    }

    public function getTicketPromedioHoyProperty(): float
    {
        if ($this->ticketsHoy === 0) return 0;
        return round($this->totalVendidoHoy / $this->ticketsHoy, 2);
    }

    public function getDescuentosHoyProperty(): float
    {
        return round((float) $this->ventasHoy->sum('descuento'), 2);
    }

    public function getVentasPorMetodoProperty(): array
    {
        return [
            'efectivo'      => round((float) $this->ventasHoy->where('metodo_pago', 'efectivo')->sum('total'), 2),
            'tarjeta'       => round((float) $this->ventasHoy->where('metodo_pago', 'tarjeta')->sum('total'), 2),
            'transferencia' => round((float) $this->ventasHoy->where('metodo_pago', 'transferencia')->sum('total'), 2),
            'mixto'         => round((float) $this->ventasHoy->where('metodo_pago', 'mixto')->sum('total'), 2),
        ];
    }

    public function getCostoInsumosVendidosHoyProperty(): float
    {
        [$inicio, $fin] = $this->rangoHoy();
        return round(
            (float) MovimientoInventario::query()
                ->where('tipo', 'venta')
                ->whereBetween('fecha_movimiento', [$inicio, $fin])
                ->sum(DB::raw('ABS(cantidad) * costo_unitario')),
            2,
        );
    }

    public function getUtilidadBrutaHoyProperty(): float
    {
        return round($this->totalVendidoHoy - $this->costoInsumosVendidosHoy, 2);
    }

    public function getMargenBrutoHoyProperty(): float
    {
        if ($this->totalVendidoHoy <= 0) return 0;
        return round(($this->utilidadBrutaHoy / $this->totalVendidoHoy) * 100, 2);
    }

    public function getUltimasVentasProperty()
    {
        return Venta::query()
            ->with(['detalles.producto', 'user'])
            ->withCount('detalles')
            ->whereIn('estado', ['pagada', 'cancelada'])
            ->orderByDesc('fecha_venta')
            ->limit(10)
            ->get();
    }

    public function getProductosMasVendidosHoyProperty()
    {
        [$inicio, $fin] = $this->rangoHoy();
        return VentaDetalle::query()
            ->join('ventas', 'ventas.id', '=', 'venta_detalles.venta_id')
            ->join('productos', 'productos.id', '=', 'venta_detalles.producto_id')
            ->where('ventas.estado', 'pagada')
            ->whereBetween('ventas.fecha_venta', [$inicio, $fin])
            ->groupBy('venta_detalles.producto_id', 'productos.nombre')
            ->orderByDesc('cantidad_vendida')
            ->limit(5)
            ->get([
                'venta_detalles.producto_id',
                'productos.nombre',
                DB::raw('SUM(venta_detalles.cantidad) as cantidad_vendida'),
                DB::raw('SUM(venta_detalles.subtotal) as total_vendido'),
                DB::raw('SUM(venta_detalles.cantidad * venta_detalles.costo_unitario_estimado) as costo_estimado'),
            ]);
    }

    public function getInsumosConsumidosHoyProperty()
    {
        [$inicio, $fin] = $this->rangoHoy();
        return MovimientoInventario::query()
            ->join('insumos', 'insumos.id', '=', 'movimiento_inventarios.insumo_id')
            ->where('movimiento_inventarios.tipo', 'venta')
            ->whereBetween('movimiento_inventarios.fecha_movimiento', [$inicio, $fin])
            ->groupBy('movimiento_inventarios.insumo_id', 'insumos.nombre', 'insumos.unidad_medida')
            ->orderByDesc('cantidad_consumida')
            ->limit(5)
            ->get([
                'movimiento_inventarios.insumo_id',
                'insumos.nombre',
                'insumos.unidad_medida',
                DB::raw('SUM(ABS(movimiento_inventarios.cantidad)) as cantidad_consumida'),
                DB::raw('SUM(ABS(movimiento_inventarios.cantidad) * movimiento_inventarios.costo_unitario) as costo_estimado_consumido'),
            ]);
    }

    public function agregarProducto(int $productoId): void
    {
        $producto = Producto::query()
            ->with(['categoria', 'productOptionGroups.optionItems.inventoryItem'])
            ->whereKey($productoId)
            ->where('activo', true)
            ->firstOrFail();

        if ($producto->product_type === 'configurable' && $producto->productOptionGroups->isNotEmpty()) {
            $hasEmptyGroups = $producto->productOptionGroups
                ->contains(fn ($group) => $group->optionItems->where('is_active', true)->isEmpty());

            if ($hasEmptyGroups) {
                session()->flash('error', 'Este producto configurable no tiene sabores u opciones cargadas todavia. Entra a Editar receta y agrega opciones al grupo Sabores.');

                return;
            }

            $this->abrirConfiguracionProducto($producto);

            return;
        }

        $this->agregarLineaCarrito(
            key: (string) $producto->id,
            producto: $producto,
        );
    }

    public function agregarProductoConfigurado(ProductConfigurationService $configurationService): void
    {
        $producto = $this->productoConfigurable;

        if (! $producto instanceof Producto) {
            $this->addError('configuracion', 'Selecciona un producto configurable.');

            return;
        }

        try {
            $configurationService->resolveConfiguration($producto, $this->selected_options);
        } catch (\Throwable $exception) {
            $this->addError('configuracion', $exception->getMessage());

            return;
        }

        $optionLabel = $this->optionLabel($producto, $this->selected_options);
        $key = $producto->id.'-'.md5(json_encode($this->selected_options));

        $this->agregarLineaCarrito(
            key: $key,
            producto: $producto,
            selectedOptions: $this->selected_options,
            optionLabel: $optionLabel,
        );

        $this->configuring_product_id = '';
        $this->selected_options = [];
        $this->resetErrorBag('configuracion');
    }

    public function cancelarConfiguracionProducto(): void
    {
        $this->configuring_product_id = '';
        $this->selected_options = [];
        $this->resetErrorBag('configuracion');
    }

    public function actualizarOpcionConfigurable(int $groupId, int $optionItemId, mixed $quantity): void
    {
        $quantity = max(0, (float) $quantity);
        $group = $this->productoConfigurable?->productOptionGroups->firstWhere('id', $groupId);

        if (! $group) {
            return;
        }

        if ($quantity <= 0) {
            unset($this->selected_options[$groupId][$optionItemId]);

            return;
        }

        $currentGroupQuantity = $this->cantidadSeleccionadaGrupo($groupId);
        $currentOptionQuantity = (float) ($this->selected_options[$groupId][$optionItemId] ?? 0);
        $maxQuantity = $this->maximoSeleccionGrupo($group);
        $availableQuantity = max(0, $maxQuantity - ($currentGroupQuantity - $currentOptionQuantity));

        if ($quantity > $availableQuantity) {
            $quantity = $availableQuantity;
        }

        if ($quantity <= 0) {
            unset($this->selected_options[$groupId][$optionItemId]);

            return;
        }

        $this->selected_options[$groupId][$optionItemId] = $quantity;
    }

    public function incrementarOpcionConfigurable(int $groupId, int $optionItemId): void
    {
        $group = $this->productoConfigurable?->productOptionGroups->firstWhere('id', $groupId);

        if (! $group) {
            return;
        }

        $selectedQuantity = $this->cantidadSeleccionadaGrupo($groupId);
        $maxQuantity = $this->maximoSeleccionGrupo($group);

        if ($selectedQuantity >= $maxQuantity) {
            return;
        }

        $currentQuantity = (float) ($this->selected_options[$groupId][$optionItemId] ?? 0);
        $this->selected_options[$groupId][$optionItemId] = $currentQuantity + min(1, $maxQuantity - $selectedQuantity);
    }

    public function disminuirOpcionConfigurable(int $groupId, int $optionItemId): void
    {
        $currentQuantity = (float) ($this->selected_options[$groupId][$optionItemId] ?? 0);
        $newQuantity = max(0, $currentQuantity - 1);

        if ($newQuantity <= 0) {
            unset($this->selected_options[$groupId][$optionItemId]);

            return;
        }

        $this->selected_options[$groupId][$optionItemId] = $newQuantity;
    }

    public function actualizarCantidad(string|int $productoId, mixed $cantidad): void
    {
        $key = (string) $productoId;

        if (! array_key_exists($key, $this->carrito)) return;
        $cantidadActualizada = (int) $cantidad;
        if ($cantidadActualizada <= 0) {
            $this->quitarProducto($key);
            return;
        }
        $this->carrito[$key]['cantidad'] = $cantidadActualizada;
        $this->recalcularItem($key);
    }

    public function quitarProducto(string|int $productoId): void
    {
        unset($this->carrito[(string) $productoId]);
    }

    private function abrirConfiguracionProducto(Producto $producto): void
    {
        $this->configuring_product_id = (string) $producto->id;
        $this->selected_options = [];

        foreach ($producto->productOptionGroups as $group) {
            $this->selected_options[$group->id] = [];
        }

        $this->resetErrorBag('configuracion');
    }

    private function cantidadSeleccionadaGrupo(int $groupId): float
    {
        return round(array_sum(array_map('floatval', $this->selected_options[$groupId] ?? [])), 3);
    }

    private function maximoSeleccionGrupo(mixed $group): float
    {
        return (float) ($group->max_quantity ?? $group->required_quantity);
    }

    /**
     * @param  array<int, array<int, int|float>>  $selectedOptions
     */
    private function agregarLineaCarrito(string $key, Producto $producto, array $selectedOptions = [], ?string $optionLabel = null): void
    {
        if (array_key_exists($key, $this->carrito)) {
            $this->carrito[$key]['cantidad']++;
        } else {
            $this->carrito[$key] = [
                'key'                    => $key,
                'producto_id'            => $producto->id,
                'nombre'                 => $producto->nombre,
                'categoria'              => $producto->categoria?->nombre,
                'precio_unitario'        => (float) $producto->precio_venta,
                'costo_unitario_estimado'=> (float) $producto->costo_estimado,
                'cantidad'               => 1,
                'subtotal'               => (float) $producto->precio_venta,
                'selected_options'       => $selectedOptions,
                'option_label'           => $optionLabel,
            ];
        }

        $this->resetErrorBag('carrito');
        $this->recalcularItem($key);
    }

    public function limpiarCarrito(): void
    {
        $this->carrito = [];
        $this->descuento = '0';
        $this->monto_recibido = '';
        $this->metodo_pago = 'efectivo';
    }

    public function toggleVentaDetalle(int $ventaId): void
    {
        $this->venta_seleccionada_id = $this->venta_seleccionada_id === (string) $ventaId
            ? ''
            : (string) $ventaId;
    }

    public function confirmarVenta(VentaService $ventaService): void
    {
        if ($this->carrito === []) {
            $this->addError('carrito', 'Agrega productos para iniciar una venta.');
            return;
        }

        $validated = $this->validate([
            'descuento'      => ['required', 'numeric', 'min:0'],
            'monto_recibido' => ['nullable', 'numeric', 'min:0'],
            'metodo_pago'    => ['required', 'in:efectivo,tarjeta,transferencia,mixto'],
        ]);

        $items = collect($this->carrito)
            ->map(fn (array $item) => [
                'producto_id'      => $item['producto_id'],
                'cantidad'         => $item['cantidad'],
                'selected_options' => $item['selected_options'] ?? [],
            ])
            ->values()
            ->all();

        try {
            $venta = $ventaService->crearVenta($items, [
                'user_id'     => auth()->id(),
                'metodo_pago' => $this->metodo_pago,
                'descuento'   => (float) $validated['descuento'],
                'fecha_venta' => now(),
            ]);
        } catch (\Throwable $exception) {
            session()->flash('error', $exception->getMessage());
            return;
        }

        $this->venta_seleccionada_id = (string) $venta->id;
        $folio = $venta->folio;
        $total = number_format((float) $venta->total, 2);
        $this->limpiarCarrito();
        session()->flash('success', "Venta {$folio} registrada correctamente por \${$total}.");
    }

    private function recalcularItem(string|int $productoId): void
    {
        $key = (string) $productoId;

        if (! array_key_exists($key, $this->carrito)) return;
        $this->carrito[$key]['subtotal'] = round(
            (float) $this->carrito[$key]['precio_unitario'] * (int) $this->carrito[$key]['cantidad'],
            2,
        );
    }

    /**
     * @param  array<int, array<int, int|float>>  $selectedOptions
     */
    private function optionLabel(Producto $producto, array $selectedOptions): string
    {
        $producto->loadMissing('productOptionGroups.optionItems.inventoryItem');

        return $producto->productOptionGroups
            ->flatMap(function ($group) use ($selectedOptions) {
                $groupSelections = $selectedOptions[$group->id] ?? [];

                return $group->optionItems
                    ->filter(fn ($option) => array_key_exists($option->id, $groupSelections))
                    ->map(fn ($option) => $option->inventoryItem?->name.' x '.number_format((float) $groupSelections[$option->id], 0));
            })
            ->filter()
            ->implode(', ');
    }

    private function rangoHoy(): array
    {
        return [now()->startOfDay(), now()->endOfDay()];
    }
};
?>
<div
    x-data="{ cartOpen: false, summaryOpen: false, catalogOpen: true }"
    class="relative -mx-4 min-h-screen bg-stone-100/70 px-4 pb-28 pt-1 xl:pb-8"
    style="font-family: 'DM Sans', sans-serif;"
>
    @if (session('success'))
        <div class="mb-4 border-l-4 border-emerald-500 bg-white px-4 py-3 text-sm font-semibold text-emerald-800">
            {{ session('success') }}
        </div>
    @endif

    @if (session('error'))
        <div class="mb-4 border-l-4 border-red-500 bg-white px-4 py-3 text-sm font-semibold text-red-800">
            {{ session('error') }}
        </div>
    @endif

    @if (! $this->cajaAbierta)
        <div class="mb-4 border-l-4 border-amber-500 bg-white px-4 py-3 text-sm text-amber-900">
            <div class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
                <div>
                    <p class="font-semibold">Abre la caja del dia antes de vender</p>
                    <p class="mt-1 text-amber-800">Las ventas deben quedar ligadas al balance diario para que el corte cuadre.</p>
                </div>
                <a href="{{ route('caja.corte') }}" wire:navigate class="inline-flex items-center justify-center bg-amber-900 px-4 py-2 text-sm font-black text-white transition hover:bg-amber-800">
                    Abrir caja
                </a>
            </div>
        </div>
    @endif

    <section class="mb-4 border border-stone-200 bg-white">
        <button type="button" @click="summaryOpen = !summaryOpen" class="flex w-full items-center justify-between px-4 py-3 text-left xl:hidden">
            <div>
                <p class="text-[11px] font-black uppercase tracking-[0.18em] text-stone-400">Total vendido hoy</p>
                <p class="mt-1 text-2xl font-black text-stone-950" style="font-family: 'Nunito', sans-serif;">${{ number_format($this->totalVendidoHoy, 2) }}</p>
            </div>
            <span class="text-xs font-bold uppercase tracking-[0.16em] text-stone-400" x-text="summaryOpen ? 'Ocultar' : 'Ver'"></span>
        </button>

        <div class="grid grid-cols-2 divide-x divide-y divide-stone-200 xl:grid-cols-4 xl:divide-y-0" :class="summaryOpen ? 'grid' : 'hidden xl:grid'">
            <div class="px-5 py-4">
                <p class="text-[11px] font-black uppercase tracking-[0.16em] text-stone-400">Total vendido hoy</p>
                <p class="mt-2 text-2xl font-black text-stone-950" style="font-family: 'Nunito', sans-serif;">${{ number_format($this->totalVendidoHoy, 2) }}</p>
            </div>
            <div class="px-5 py-4">
                <p class="text-[11px] font-black uppercase tracking-[0.16em] text-stone-400">Tickets</p>
                <p class="mt-2 text-2xl font-black text-stone-950" style="font-family: 'Nunito', sans-serif;">{{ $this->ticketsHoy }}</p>
            </div>
            <div class="px-5 py-4">
                <p class="text-[11px] font-black uppercase tracking-[0.16em] text-stone-400">Ticket promedio</p>
                <p class="mt-2 text-2xl font-black text-stone-950" style="font-family: 'Nunito', sans-serif;">${{ number_format($this->ticketPromedioHoy, 2) }}</p>
            </div>
            <div class="px-5 py-4">
                <p class="text-[11px] font-black uppercase tracking-[0.16em] text-stone-400">Margen bruto</p>
                <p class="mt-2 text-2xl font-black text-stone-950" style="font-family: 'Nunito', sans-serif;">{{ number_format($this->margenBrutoHoy, 1) }}%</p>
                <p class="mt-2 text-xs font-semibold text-stone-500">Costo ${{ number_format($this->costoInsumosVendidosHoy, 2) }}</p>
                <p class="text-xs font-semibold text-emerald-700">Utilidad ${{ number_format($this->utilidadBrutaHoy, 2) }}</p>
            </div>
        </div>
    </section>

    <section class="overflow-hidden border border-stone-200 bg-white xl:grid xl:grid-cols-[minmax(0,1fr)_390px]">
        <div class="min-w-0 xl:border-r xl:border-stone-200">
            <header class="border-b border-stone-200 px-5 py-5 sm:px-6">
                <div class="flex flex-col gap-4 lg:flex-row lg:items-end lg:justify-between">
                    <div>
                        <p class="text-[11px] font-black uppercase tracking-[0.18em] text-orange-500">Punto de venta</p>
                        <h1 class="mt-1 text-2xl font-black text-stone-950" style="font-family: 'Nunito', sans-serif;">Productos</h1>
                        <p class="mt-1 text-sm text-stone-500">Busca, filtra y agrega productos al carrito sin cambiar de pantalla.</p>
                    </div>
                    <button type="button" wire:click="limpiarMenuVenta" class="w-fit border border-stone-200 px-4 py-2 text-sm font-bold text-stone-600 transition hover:bg-stone-50">
                        Limpiar filtros
                    </button>
                </div>

                <div class="mt-5 grid gap-3 lg:grid-cols-[minmax(0,1fr)_220px]">
                    <input
                        type="search"
                        wire:model.live.debounce.250ms="search"
                        class="h-12 w-full border border-stone-200 bg-white px-4 text-sm font-semibold text-stone-900 outline-none transition placeholder:text-stone-400 focus:border-stone-500"
                        placeholder="Buscar producto"
                    >
                    <select wire:model.live="categoria_producto_id" class="h-12 w-full border border-stone-200 bg-white px-4 text-sm font-semibold text-stone-700 outline-none transition focus:border-stone-500">
                        <option value="">Todas las categorias</option>
                        @foreach ($this->categorias as $categoria)
                            <option value="{{ $categoria->id }}">{{ $categoria->nombre }}</option>
                        @endforeach
                    </select>
                </div>

                <div class="mt-4 flex gap-2 overflow-x-auto pb-1">
                    <button type="button" wire:click="$set('categoria_producto_id', '')" class="shrink-0 rounded-full px-4 py-2 text-sm font-black transition {{ $categoria_producto_id === '' ? 'bg-stone-950 text-white' : 'border border-stone-200 text-stone-600 hover:bg-stone-50' }}">
                        Todo
                    </button>
                    @foreach ($this->categorias as $categoria)
                        <button type="button" wire:key="categoria-chip-{{ $categoria->id }}" wire:click="$set('categoria_producto_id', '{{ $categoria->id }}')" class="shrink-0 rounded-full px-4 py-2 text-sm font-black transition {{ $categoria_producto_id === (string) $categoria->id ? 'bg-stone-950 text-white' : 'border border-stone-200 text-stone-600 hover:bg-stone-50' }}">
                            {{ $categoria->nombre }}
                        </button>
                    @endforeach
                </div>
            </header>

            @if ($this->productoConfigurable)
                <div class="border-b border-orange-200 bg-orange-50/60 px-5 py-5 sm:px-6">
                    <div class="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
                        <div>
                            <p class="text-[11px] font-black uppercase tracking-[0.18em] text-orange-500">Configurable</p>
                            <h2 class="mt-1 text-xl font-black text-stone-950" style="font-family: 'Nunito', sans-serif;">Configurar {{ $this->productoConfigurable->nombre }}</h2>
                            <p class="mt-1 text-sm text-stone-600">Elige sabores. Puedes repetir el mismo sabor mientras no exceda el maximo del grupo.</p>
                        </div>
                        <button type="button" wire:click="cancelarConfiguracionProducto" class="text-sm font-bold text-stone-500 hover:text-stone-900">Cancelar</button>
                    </div>

                    @error('configuracion')
                        <div class="mt-4 border border-red-200 bg-white px-4 py-3 text-sm font-semibold text-red-700">{{ $message }}</div>
                    @enderror

                    <div class="mt-4 space-y-4">
                        @foreach ($this->productoConfigurable->productOptionGroups as $group)
                            @php
                                $selectedInGroup = $this->cantidadSeleccionadaGrupo($group->id);
                                $requiredQuantity = (float) $group->required_quantity;
                                $maxQuantity = $this->maximoSeleccionGrupo($group);
                            @endphp
                            <div wire:key="config-group-{{ $group->id }}" class="border border-stone-200 bg-white">
                                <div class="flex items-center justify-between border-b border-stone-200 px-4 py-3">
                                    <div>
                                        <p class="font-black text-stone-950">{{ $group->name }}</p>
                                        <p class="text-xs text-stone-500">Seleccionado {{ number_format($selectedInGroup, 0) }} de {{ number_format($maxQuantity, 0) }}</p>
                                    </div>
                                    <span class="rounded-full bg-orange-100 px-3 py-1 text-xs font-black text-orange-700">Requiere {{ number_format($requiredQuantity, 0) }}</span>
                                </div>

                                <div class="divide-y divide-stone-100">
                                    @foreach ($group->optionItems->where('is_active', true) as $option)
                                        @php
                                            $selectedQuantity = (float) ($selected_options[$group->id][$option->id] ?? 0);
                                            $consumeQuantity = (float) $option->quantity_per_selection;
                                            $unit = $option->inventoryItem?->unit?->abbreviation ?? $option->inventoryItem?->unit?->name ?? '';
                                        @endphp
                                        <div wire:key="config-option-{{ $option->id }}" class="grid grid-cols-[minmax(0,1fr)_128px] items-center gap-3 px-4 py-3">
                                            <div class="min-w-0">
                                                <p class="truncate text-sm font-black text-stone-900">{{ $option->inventoryItem?->name ?? 'Opcion sin insumo' }}</p>
                                                <p class="mt-0.5 text-xs text-stone-500">Consume {{ number_format($consumeQuantity, 3) }} {{ $unit }}</p>
                                            </div>
                                            <div class="flex items-center justify-end gap-2">
                                                <button type="button" wire:click="disminuirOpcionConfigurable({{ $group->id }}, {{ $option->id }})" class="flex size-9 items-center justify-center border border-stone-200 bg-white text-lg font-black text-stone-700 transition hover:bg-stone-50">-</button>
                                                <span class="w-8 text-center text-sm font-black text-stone-950">{{ number_format($selectedQuantity, 0) }}</span>
                                                <button type="button" wire:click="incrementarOpcionConfigurable({{ $group->id }}, {{ $option->id }})" class="flex size-9 items-center justify-center border border-stone-200 bg-white text-lg font-black text-stone-700 transition hover:bg-stone-50">+</button>
                                            </div>
                                        </div>
                                    @endforeach
                                </div>
                            </div>
                        @endforeach
                    </div>

                    <button type="button" wire:click="agregarProductoConfigurado" class="mt-4 w-full bg-orange-500 px-5 py-3 text-sm font-black text-white transition hover:bg-orange-600">
                        Agregar configurado
                    </button>
                </div>
            @endif

            <div class="divide-y divide-stone-200">
                @forelse ($this->productos as $producto)
                    <div wire:key="producto-venta-{{ $producto->id }}" class="grid gap-3 px-5 py-4 transition hover:bg-stone-50 sm:grid-cols-[minmax(0,1fr)_auto] sm:items-center sm:px-6">
                        <div class="min-w-0">
                            <div class="flex flex-wrap items-center gap-2">
                                <h3 class="min-w-0 truncate text-base font-black text-stone-950" style="font-family: 'Nunito', sans-serif;">{{ $producto->nombre }}</h3>
                                @if ($producto->product_type === 'configurable')
                                    <span class="rounded-full bg-sky-100 px-2.5 py-1 text-xs font-black text-sky-700">Configurable</span>
                                @endif
                            </div>
                            <p class="mt-1 text-sm text-stone-500">{{ $producto->categoria?->nombre ?? 'Sin categoria' }}</p>
                        </div>
                        <div class="flex items-center justify-between gap-4 sm:justify-end">
                            <p class="font-mono text-xl font-black text-stone-950">${{ number_format((float) $producto->precio_venta, 2) }}</p>
                            <button type="button" wire:click="agregarProducto({{ $producto->id }})" class="bg-stone-950 px-4 py-2 text-sm font-black text-white transition hover:bg-stone-800">
                                {{ $producto->product_type === 'configurable' ? 'Elegir' : 'Agregar' }}
                            </button>
                        </div>
                    </div>
                @empty
                    <div class="px-5 py-12 text-center text-sm text-stone-400">No hay productos con esos filtros.</div>
                @endforelse
            </div>
        </div>

        <aside class="bg-white">
            <div class="sticky top-0 xl:top-4">
                <header class="border-b border-stone-200 px-5 py-5">
                    <div class="flex items-center justify-between">
                        <div>
                            <p class="text-[11px] font-black uppercase tracking-[0.18em] text-stone-400">Cobro</p>
                            <h2 class="mt-1 text-2xl font-black text-stone-950" style="font-family: 'Nunito', sans-serif;">Carrito</h2>
                        </div>
                        @if ($carrito !== [])
                            <button type="button" wire:click="limpiarCarrito" class="text-sm font-bold text-stone-500 hover:text-red-600">Limpiar</button>
                        @endif
                    </div>
                </header>

                @error('carrito')
                    <div class="mx-5 mt-4 border border-red-200 bg-red-50 px-4 py-3 text-sm font-semibold text-red-700">{{ $message }}</div>
                @enderror

                <div class="max-h-[38vh] divide-y divide-stone-200 overflow-y-auto xl:max-h-[42vh]">
                    @forelse ($carrito as $item)
                        <div wire:key="carrito-{{ $item['key'] ?? $item['producto_id'] }}" class="px-5 py-4">
                            <div class="flex items-start justify-between gap-3">
                                <div class="min-w-0">
                                    <p class="truncate text-sm font-black text-stone-950">{{ $item['nombre'] }}</p>
                                    @if (! empty($item['option_label']))
                                        <p class="mt-1 line-clamp-2 text-xs text-sky-700">{{ $item['option_label'] }}</p>
                                    @endif
                                    <p class="mt-1 text-xs text-stone-500">${{ number_format((float) $item['precio_unitario'], 2) }} c/u</p>
                                </div>
                                <button type="button" wire:click="quitarProducto('{{ $item['key'] ?? $item['producto_id'] }}')" class="text-xs font-bold text-stone-400 hover:text-red-600">Quitar</button>
                            </div>
                            <div class="mt-3 flex items-center justify-between gap-3">
                                <input type="number" min="1" wire:model.live="carrito.{{ $item['key'] ?? $item['producto_id'] }}.cantidad" wire:change="actualizarCantidad('{{ $item['key'] ?? $item['producto_id'] }}', $event.target.value)" class="h-10 w-20 border border-stone-200 px-3 text-center text-sm font-black text-stone-900 outline-none focus:border-stone-500">
                                <p class="font-mono text-base font-black text-stone-950">${{ number_format((float) $item['subtotal'], 2) }}</p>
                            </div>
                        </div>
                    @empty
                        <div class="px-5 py-12 text-center">
                            <p class="text-sm font-black text-stone-700">Carrito vacio</p>
                            <p class="mt-1 text-xs text-stone-400">Agrega productos para iniciar la venta.</p>
                        </div>
                    @endforelse
                </div>

                <div class="border-t border-stone-200 px-5 py-5">
                    <div class="grid grid-cols-2 gap-3">
                        <label class="block">
                            <span class="text-xs font-bold text-stone-500">Descuento</span>
                            <input type="number" step="0.01" wire:model.live="descuento" class="mt-1 h-11 w-full border border-stone-200 px-3 text-sm font-semibold outline-none focus:border-stone-500">
                        </label>
                        <label class="block">
                            <span class="text-xs font-bold text-stone-500">Monto recibido</span>
                            <input type="number" step="0.01" wire:model.live="monto_recibido" class="mt-1 h-11 w-full border border-stone-200 px-3 text-sm font-semibold outline-none focus:border-stone-500">
                        </label>
                    </div>

                    <div class="mt-4">
                        <p class="mb-2 text-xs font-bold text-stone-500">Metodo de pago</p>
                        <div class="grid grid-cols-2 gap-2">
                            @foreach (['efectivo' => 'Efectivo', 'tarjeta' => 'Tarjeta', 'transferencia' => 'Transferencia', 'mixto' => 'Mixto'] as $metodo => $label)
                                <button type="button" wire:click="$set('metodo_pago', '{{ $metodo }}')" class="border px-3 py-2 text-sm font-black transition {{ $metodo_pago === $metodo ? 'border-orange-500 bg-orange-500 text-white' : 'border-stone-200 bg-white text-stone-600 hover:bg-stone-50' }}">
                                    {{ $label }}
                                </button>
                            @endforeach
                        </div>
                    </div>

                    <div class="mt-5 space-y-2 border-t border-stone-200 pt-4 text-sm">
                        <div class="flex justify-between text-stone-500"><span>Subtotal</span><span class="font-mono">${{ number_format($this->subtotal, 2) }}</span></div>
                        <div class="flex justify-between text-stone-500"><span>Descuento</span><span class="font-mono">-${{ number_format((float) $descuento, 2) }}</span></div>
                        <div class="flex justify-between text-stone-500"><span>Cambio</span><span class="font-mono">${{ number_format($this->cambio, 2) }}</span></div>
                        <div class="flex items-end justify-between pt-2 text-stone-950">
                            <span class="text-base font-black">Total</span>
                            <span class="font-mono text-3xl font-black">${{ number_format($this->total, 2) }}</span>
                        </div>
                    </div>

                    <button type="button" wire:click="confirmarVenta" @disabled($carrito === [] || ! $this->cajaAbierta) class="mt-5 w-full bg-orange-500 px-5 py-4 text-sm font-black text-white transition hover:bg-orange-600 disabled:cursor-not-allowed disabled:bg-orange-200">
                        @if (! $this->cajaAbierta)
                            Abre caja para vender
                        @elseif ($carrito === [])
                            Sin productos en carrito
                        @else
                            Cobrar venta
                        @endif
                    </button>
                </div>
            </div>
        </aside>
    </section>
{{-- Reportes operativos --}}

    <div class="mt-4 border border-stone-200 bg-white">

        {{-- Historial de ventas + Top productos --}}
        <div class="grid xl:grid-cols-[1.35fr_0.65fr] xl:divide-x xl:divide-stone-200">

            {{-- Historial reciente --}}
            <div class="overflow-hidden bg-white">
                <div class="border-b border-stone-200 px-5 py-4">
                    <h2 class="text-base font-bold text-stone-900" style="font-family: 'Nunito', sans-serif;">
                        Últimas ventas
                    </h2>
                    <p class="text-xs text-stone-500 mt-0.5">10 mas recientes</p>
                </div>

                {{-- Desktop: tabla --}}
                <div class="hidden sm:block overflow-x-auto">
                    <table class="min-w-full border-collapse text-sm">
                        <thead>
                            <tr class="border-b border-stone-200 bg-white text-xs font-black uppercase tracking-[0.14em] text-stone-400">
                                <th class="px-5 py-3 text-left">Folio</th>
                                <th class="px-4 py-3 text-left">Hora</th>
                                <th class="px-4 py-3 text-right">Total</th>
                                <th class="px-4 py-3 text-left hidden md:table-cell">Metodo</th>
                                <th class="px-4 py-3 text-left">Estado</th>
                                <th class="px-4 py-3 text-center">Items</th>
                                <th class="px-4 py-3 text-right"></th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-stone-200">
                            @forelse ($this->ultimasVentas as $venta)
                                <tr wire:key="venta-reciente-{{ $venta->id }}" class="align-top transition hover:bg-stone-50">
                                    <td class="px-5 py-3 font-mono text-xs font-bold text-stone-700">
                                        {{ $venta->folio }}
                                    </td>
                                    <td class="px-4 py-3 text-xs text-stone-500">
                                        {{ optional($venta->fecha_venta)->format('H:i') ?? '-' }}
                                        <span class="block text-[11px] text-stone-400">
                                            {{ optional($venta->fecha_venta)->format('d/m') }}
                                        </span>
                                    </td>
                                    <td class="px-4 py-3 text-right font-bold text-stone-900 font-mono">
                                        ${{ number_format((float) $venta->total, 2) }}
                                    </td>
                                    <td class="px-4 py-3 hidden md:table-cell">
                                        <span class="text-xs text-stone-500 capitalize">{{ $venta->metodo_pago }}</span>
                                    </td>
                                    <td class="px-4 py-3">
                                        @if ($venta->estado === 'pagada')
                                            <span class="inline-flex items-center gap-1 rounded-full bg-emerald-100 px-2.5 py-0.5 text-xs font-semibold text-emerald-700">
                                                <span class="h-1.5 w-1.5 rounded-full bg-emerald-500"></span>
                                                Pagada
                                            </span>
                                        @else
                                            <span class="inline-flex items-center gap-1 rounded-full bg-stone-100 px-2.5 py-0.5 text-xs font-semibold text-stone-500">
                                                <span class="h-1.5 w-1.5 rounded-full bg-stone-400"></span>
                                                {{ ucfirst($venta->estado) }}
                                            </span>
                                        @endif
                                    </td>
                                    <td class="px-4 py-3 text-center text-xs text-stone-500">
                                        {{ $venta->detalles_count }}
                                    </td>
                                    <td class="px-4 py-3 text-right">
                                        <button
                                            type="button"
                                            wire:click="toggleVentaDetalle({{ $venta->id }})"
                                            class="text-xs font-semibold text-sky-600 hover:text-sky-800 transition-colors"
                                        >
                                            {{ $venta_seleccionada_id === (string) $venta->id ? 'Ocultar' : 'Ver' }}
                                        </button>
                                    </td>
                                </tr>

                                @if ($venta_seleccionada_id === (string) $venta->id)
                                    <tr wire:key="venta-reciente-detalle-{{ $venta->id }}" class="bg-stone-50/80">
                                        <td colspan="7" class="px-5 py-3">
                                            @if ($venta->detalles->isEmpty())
                                                <p class="text-xs text-stone-400 italic">Sin detalles registrados.</p>
                                            @else
                                                <div class="flex flex-wrap gap-2">
                                                    @foreach ($venta->detalles as $detalle)
                                                        <div class="border-l border-stone-300 bg-white px-3 py-2 text-xs">
                                                            <p class="font-semibold text-stone-800">
                                                                {{ $detalle->producto?->nombre ?? 'Producto eliminado' }}
                                                            </p>
                                                            <p class="text-stone-500 mt-0.5">
                                                                {{ $detalle->cantidad }} x ${{ number_format((float) $detalle->precio_unitario, 2) }}
                                                                <span class="font-bold text-stone-800 ml-1">= ${{ number_format((float) $detalle->subtotal, 2) }}</span>
                                                            </p>
                                                        </div>
                                                    @endforeach
                                                </div>
                                            @endif
                                        </td>
                                    </tr>
                                @endif
                            @empty
                                <tr>
                                    <td colspan="7" class="py-12 text-center">
                                        <p class="text-sm text-stone-400">Aun no hay ventas registradas</p>

                                    </td>
                                </tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>

                {{-- Mobile: cards --}}
                <div class="sm:hidden divide-y divide-stone-100">
                    @forelse ($this->ultimasVentas as $venta)
                        <div wire:key="venta-mobile-{{ $venta->id }}" class="px-4 py-3">
                            <div class="flex items-start justify-between">
                                <div>
                                    <p class="text-xs font-mono font-bold text-stone-600">{{ $venta->folio }}</p>
                                    <p class="text-xs text-stone-400 mt-0.5">
                                        {{ optional($venta->fecha_venta)->format('d/m H:i') ?? '-' }}
                                        | {{ $venta->metodo_pago }}
                                    </p>
                                </div>
                                <div class="text-right">
                                    <p class="font-bold font-mono text-stone-900">
                                        ${{ number_format((float) $venta->total, 2) }}
                                    </p>
                                    @if ($venta->estado === 'pagada')
                                        <span class="text-[11px] font-semibold text-emerald-600">Pagada</span>
                                    @else
                                        <span class="text-[11px] font-semibold text-stone-400">{{ ucfirst($venta->estado) }}</span>
                                    @endif
                                </div>
                            </div>
                        </div>
                    @empty
                        <div class="py-10 text-center">
                            <p class="text-sm text-stone-400">Sin ventas registradas</p>
                        </div>
                    @endforelse
                </div>
            </div>

            {{-- Top productos + Insumos consumidos --}}
            <div class="divide-y divide-stone-200">

                {{-- Top 5 productos --}}
                <div class="overflow-hidden bg-white">
                    <div class="border-b border-stone-200 px-5 py-4">
                        <h2 class="text-sm font-bold text-stone-900" style="font-family: 'Nunito', sans-serif;">
                            Top productos hoy
                        </h2>
                    </div>
                    <div class="divide-y divide-stone-200">
                        @forelse ($this->productosMasVendidosHoy as $i => $producto)
                            <div wire:key="top-{{ $producto->producto_id }}" class="flex items-center gap-3 px-4 py-3">
                                <span class="text-sm font-bold text-stone-300 w-5 shrink-0">{{ $i + 1 }}</span>
                                <div class="flex-1 min-w-0">
                                    <p class="text-sm font-semibold text-stone-900 truncate">{{ $producto->nombre }}</p>
                                    <p class="text-xs text-stone-400">
                                        {{ number_format((float) $producto->cantidad_vendida, 0) }} uds.
                                    </p>
                                </div>
                                <div class="text-right shrink-0">
                                    <p class="text-sm font-bold text-emerald-700 font-mono">
                                        ${{ number_format((float) $producto->total_vendido, 2) }}
                                    </p>
                                </div>
                            </div>
                        @empty
                            <div class="px-4 py-8 text-center text-sm text-stone-400">
                                Sin ventas aun hoy
                            </div>
                        @endforelse
                    </div>
                </div>

                {{-- Insumos consumidos --}}
                <div class="overflow-hidden bg-white">
                    <div class="border-b border-stone-200 px-5 py-4">
                        <h2 class="text-sm font-bold text-stone-900" style="font-family: 'Nunito', sans-serif;">
                            Insumos consumidos
                        </h2>
                    </div>
                    <div class="divide-y divide-stone-200">
                        @forelse ($this->insumosConsumidosHoy as $insumo)
                            <div wire:key="insumo-consumido-{{ $insumo->insumo_id }}" class="flex items-center gap-3 px-4 py-3">
                                <div class="flex-1 min-w-0">
                                    <p class="text-sm font-semibold text-stone-900 truncate">{{ $insumo->nombre }}</p>
                                    <p class="text-xs text-stone-400">
                                        {{ number_format((float) $insumo->cantidad_consumida, 3) }} {{ $insumo->unidad_medida }}
                                    </p>
                                </div>
                                <p class="text-sm font-bold text-rose-600 font-mono shrink-0">
                                    ${{ number_format((float) $insumo->costo_estimado_consumido, 2) }}
                                </p>
                            </div>
                        @empty
                            <div class="px-4 py-8 text-center text-sm text-stone-400">
                                Sin insumos consumidos hoy
                            </div>
                        @endforelse
                    </div>
                </div>

                {{-- Ventas por metodo --}}
                <div class="overflow-hidden bg-white">
                    <div class="border-b border-stone-200 px-5 py-4">
                        <h2 class="text-sm font-bold text-stone-900" style="font-family: 'Nunito', sans-serif;">
                            Por metodo de pago
                        </h2>
                    </div>
                    <div class="divide-y divide-stone-200">
                        @foreach (['efectivo' => 'Efectivo', 'tarjeta' => 'Tarjeta', 'transferencia' => 'Transferencia', 'mixto' => 'Mixto'] as $metodo => $label)
                            @if ($this->ventasPorMetodo[$metodo] > 0)
                                <div wire:key="metodo-{{ $metodo }}" class="flex items-center justify-between px-4 py-3">
                                    <div class="flex items-center gap-2">
                                        <span class="text-sm font-medium text-stone-700">{{ $label }}</span>

                                    </div>
                                    <span class="text-sm font-bold text-stone-900 font-mono">
                                        ${{ number_format((float) $this->ventasPorMetodo[$metodo], 2) }}
                                    </span>
                                </div>
                            @endif
                        @endforeach
                        @if (array_sum($this->ventasPorMetodo) === 0.0)
                            <div class="px-4 py-6 text-center text-sm text-stone-400">Sin ventas hoy</div>
                        @endif
                    </div>
                </div>

            </div>
        </div>

    </div>{{-- fin reportes --}}

</div>{{-- fin x-data --}}

</div>

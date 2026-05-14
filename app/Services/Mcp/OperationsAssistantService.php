<?php

namespace App\Services\Mcp;

use App\Models\CategoriaProducto;
use App\Models\CorteCaja;
use App\Models\Insumo;
use App\Models\InventoryItem;
use App\Models\Producto;
use App\Models\Venta;
use App\Services\InventarioService;
use App\Services\InventoryEntryService;
use App\Services\InventoryMovementService;
use App\Services\ProductConfigurationService;
use App\Services\VentaService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use InvalidArgumentException;
use RuntimeException;
use Throwable;

class OperationsAssistantService
{
    public function __construct(
        private readonly ProductConfigurationService $productConfigurationService,
        private readonly VentaService $ventaService,
        private readonly InventarioService $inventarioService,
        private readonly InventoryEntryService $inventoryEntryService,
        private readonly InventoryMovementService $inventoryMovementService,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function manual(): array
    {
        return [
            'titulo' => 'Manual MCP de operaciones',
            'reglas_generales' => [
                'Consulta primero antes de modificar datos.',
                'No inventes productos, precios, stock ni metodos de pago.',
                'Toda escritura requiere preparar la operacion y confirmar con confirmation_token.',
                'Si faltan sabores u opciones configurables, pregunta al usuario antes de preparar la venta.',
            ],
            'flujos' => [
                'registrar_venta' => [
                    'buscar_producto',
                    'estimar_venta',
                    'preparar_venta',
                    'confirmar_venta solo si el usuario confirma el resumen',
                ],
                'consultar_inventario' => [
                    'consultar_inventario para stock general o bajo',
                    'buscar_producto para saber como descuenta un producto',
                ],
                'abrir_caja' => [
                    'resumen_caja',
                    'preparar_abrir_caja',
                    'confirmar_abrir_caja solo si el usuario confirma monto inicial',
                ],
                'cerrar_caja' => [
                    'resumen_caja',
                    'preparar_cerrar_caja con monto contado',
                    'confirmar_cerrar_caja solo si el usuario confirma diferencia',
                ],
            ],
            'nunca_hacer' => [
                'No ejecutar SQL libre.',
                'No confirmar ventas, caja o inventario sin confirmation_token.',
                'No registrar venta si no hay caja abierta.',
                'No modificar precios o catalogo desde estas tools de operaciones.',
            ],
            'ejemplos' => [
                'Vendi 2 conos dobles en efectivo' => 'buscar_producto, estimar_venta, preparar_venta, confirmar_venta',
                'Que inventario esta bajo' => 'consultar_inventario',
                'Abre caja con 500' => 'resumen_caja, preparar_abrir_caja, confirmar_abrir_caja',
                'Cierra caja con 2730 contado' => 'resumen_caja, preparar_cerrar_caja, confirmar_cerrar_caja',
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function catalogSummary(): array
    {
        $activeProducts = Producto::query()->where('activo', true)->count();
        $lowInventory = $this->inventoryItemsQuery()
            ->get()
            ->filter(fn (InventoryItem $item): bool => $this->isLowInventoryItem($item))
            ->values();

        return [
            'categorias_producto' => CategoriaProducto::query()
                ->where('activo', true)
                ->orderBy('nombre')
                ->get(['id', 'nombre'])
                ->map(fn (CategoriaProducto $category): array => [
                    'id' => $category->id,
                    'nombre' => $category->nombre,
                ])
                ->all(),
            'productos_activos' => $activeProducts,
            'inventario_bajo_total' => $lowInventory->count(),
            'inventario_bajo' => $lowInventory
                ->take(10)
                ->map(fn (InventoryItem $item): array => $this->inventoryItemPayload($item))
                ->all(),
            'caja' => $this->cashSummary()['caja'],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function inventorySnapshot(?string $search = null, bool $onlyLow = false): array
    {
        $items = $this->inventoryItemsQuery()
            ->when(filled($search), function ($query) use ($search): void {
                $query->where('name', 'like', '%'.trim((string) $search).'%');
            })
            ->get()
            ->filter(fn (InventoryItem $item): bool => ! $onlyLow || $this->isLowInventoryItem($item))
            ->values();

        return [
            'total_items' => $items->count(),
            'solo_bajo_inventario' => $onlyLow,
            'items' => $items
                ->map(fn (InventoryItem $item): array => $this->inventoryItemPayload($item))
                ->all(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function searchProducts(?string $search = null, ?int $categoryId = null, bool $activeOnly = true): array
    {
        $products = Producto::query()
            ->with([
                'categoria',
                'inventoryItem.unit',
                'productRecipes.inventoryItem.unit',
                'productOptionGroups.optionItems.inventoryItem.unit',
            ])
            ->when($activeOnly, fn ($query) => $query->where('activo', true))
            ->when(filled($search), function ($query) use ($search): void {
                $query->where('nombre', 'like', '%'.trim((string) $search).'%');
            })
            ->when($categoryId !== null, fn ($query) => $query->where('categoria_producto_id', $categoryId))
            ->orderBy('nombre')
            ->limit(25)
            ->get();

        return [
            'total' => $products->count(),
            'productos' => $products
                ->map(fn (Producto $product): array => $this->productPayload($product))
                ->all(),
        ];
    }

    /**
     * @param  array<int, array<string, mixed>>  $items
     * @return array<string, mixed>
     */
    public function estimateSale(array $items, float $discount = 0, string $paymentMethod = 'efectivo'): array
    {
        if ($items === []) {
            throw new InvalidArgumentException('La venta debe incluir al menos un item.');
        }

        if (! in_array($paymentMethod, ['efectivo', 'tarjeta', 'transferencia', 'mixto'], true)) {
            throw new InvalidArgumentException('Metodo de pago no valido.');
        }

        $lines = [];
        $requirements = [];
        $subtotal = 0.0;
        $errors = [];

        foreach ($items as $index => $item) {
            $product = Producto::query()
                ->with([
                    'insumos',
                    'inventoryItem.unit',
                    'productRecipes.inventoryItem.unit',
                    'productOptionGroups.optionItems.inventoryItem.unit',
                ])
                ->whereKey($item['producto_id'] ?? null)
                ->where('activo', true)
                ->first();

            if (! $product instanceof Producto) {
                $errors[] = "El item {$index} no corresponde a un producto activo.";

                continue;
            }

            $quantity = (float) ($item['cantidad'] ?? 0);

            if ($quantity <= 0) {
                $errors[] = "La cantidad de {$product->nombre} debe ser mayor a cero.";

                continue;
            }

            $lineSubtotal = round((float) $product->precio_venta * $quantity, 2);
            $subtotal += $lineSubtotal;

            $lines[] = [
                'producto_id' => $product->id,
                'nombre' => $product->nombre,
                'cantidad' => $quantity,
                'precio_unitario' => round((float) $product->precio_venta, 2),
                'subtotal' => $lineSubtotal,
                'tipo' => $product->product_type,
            ];

            try {
                foreach ($this->stockRequirementsForProduct($product, $quantity, $item['selected_options'] ?? []) as $requirement) {
                    $requirements[] = $requirement;
                }
            } catch (Throwable $throwable) {
                $errors[] = $throwable->getMessage();
            }
        }

        $subtotal = round($subtotal, 2);
        $discount = round($discount, 2);

        if ($discount < 0) {
            $errors[] = 'El descuento no puede ser negativo.';
        }

        if ($discount > $subtotal) {
            $errors[] = 'El descuento no puede ser mayor al subtotal.';
        }

        $mergedRequirements = $this->mergeStockRequirements($requirements);
        $stockErrors = collect($mergedRequirements)
            ->filter(fn (array $requirement): bool => ! $requirement['suficiente'])
            ->map(fn (array $requirement): string => "Inventario insuficiente para {$requirement['nombre']}.")
            ->values()
            ->all();

        return [
            'puede_confirmarse' => $errors === [] && $stockErrors === [],
            'metodo_pago' => $paymentMethod,
            'subtotal' => $subtotal,
            'descuento' => $discount,
            'total' => round(max(0, $subtotal - $discount), 2),
            'lineas' => $lines,
            'impacto_inventario' => $mergedRequirements,
            'errores' => array_values([...$errors, ...$stockErrors]),
        ];
    }

    /**
     * @param  array<int, array<string, mixed>>  $items
     * @return array<string, mixed>
     */
    public function prepareSale(array $items, float $discount = 0, string $paymentMethod = 'efectivo'): array
    {
        $estimate = $this->estimateSale($items, $discount, $paymentMethod);
        $hasOpenCashRegister = CorteCaja::query()->abiertaDelDia()->exists();

        if (! $hasOpenCashRegister) {
            $estimate['errores'][] = 'No hay una caja del dia abierta. Abre caja antes de registrar ventas.';
            $estimate['puede_confirmarse'] = false;
        }

        if (! $estimate['puede_confirmarse']) {
            return $this->operationBlocked('preparar_venta', $estimate['errores'], $estimate);
        }

        return $this->withConfirmationToken('venta', [
            'items' => $items,
            'descuento' => $discount,
            'metodo_pago' => $paymentMethod,
        ], $estimate);
    }

    /**
     * @return array<string, mixed>
     */
    public function confirmSale(string $token): array
    {
        $confirmation = $this->pullConfirmation($token, 'venta');
        $payload = $confirmation['payload'];

        $sale = $this->ventaService->crearVenta($payload['items'], [
            'user_id' => $this->operatorUserId(),
            'metodo_pago' => $payload['metodo_pago'],
            'descuento' => (float) $payload['descuento'],
            'fecha_venta' => now(),
        ]);

        return [
            'status' => 'confirmed',
            'operacion' => 'venta',
            'venta' => [
                'id' => $sale->id,
                'folio' => $sale->folio,
                'subtotal' => (float) $sale->subtotal,
                'descuento' => (float) $sale->descuento,
                'total' => (float) $sale->total,
                'metodo_pago' => $sale->metodo_pago,
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function cashSummary(): array
    {
        $cashRegister = CorteCaja::query()
            ->with(['ventas.detalles.producto', 'gastos'])
            ->where('estado', 'abierto')
            ->latest('fecha_apertura')
            ->first();

        if (! $cashRegister instanceof CorteCaja) {
            return [
                'caja' => [
                    'abierta' => false,
                    'mensaje' => 'No hay caja abierta.',
                ],
            ];
        }

        $paidSales = $cashRegister->ventas->where('estado', 'pagada');
        $cashSales = $this->sumSalesByPaymentMethod($paidSales, 'efectivo');
        $shiftExpenses = round((float) $cashRegister->gastos->where('origen', 'caja_dia')->sum('monto'), 2);
        $expectedCash = round((float) $cashRegister->monto_inicial + $cashSales - $shiftExpenses, 2);

        return [
            'caja' => [
                'abierta' => true,
                'id' => $cashRegister->id,
                'fecha_apertura' => $cashRegister->fecha_apertura
                    ? Carbon::parse($cashRegister->fecha_apertura)->toISOString()
                    : null,
                'monto_inicial' => (float) $cashRegister->monto_inicial,
                'ventas_efectivo' => $cashSales,
                'ventas_tarjeta' => $this->sumSalesByPaymentMethod($paidSales, 'tarjeta'),
                'ventas_transferencia' => $this->sumSalesByPaymentMethod($paidSales, 'transferencia'),
                'ventas_mixto' => $this->sumSalesByPaymentMethod($paidSales, 'mixto'),
                'gastos_turno' => $shiftExpenses,
                'monto_esperado_efectivo' => $expectedCash,
                'tickets' => $paidSales->count(),
                'total_ventas' => round((float) $paidSales->sum('total'), 2),
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function prepareOpenCashRegister(float $initialAmount): array
    {
        if ($initialAmount < 0) {
            return $this->operationBlocked('preparar_abrir_caja', ['El monto inicial no puede ser negativo.']);
        }

        if (CorteCaja::query()->where('estado', 'abierto')->exists()) {
            return $this->operationBlocked('preparar_abrir_caja', ['Ya existe una caja abierta.']);
        }

        return $this->withConfirmationToken('abrir_caja', [
            'monto_inicial' => round($initialAmount, 2),
        ], [
            'monto_inicial' => round($initialAmount, 2),
            'mensaje' => 'Se abrira una caja nueva para el turno.',
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    public function confirmOpenCashRegister(string $token): array
    {
        $confirmation = $this->pullConfirmation($token, 'abrir_caja');
        $payload = $confirmation['payload'];

        if (CorteCaja::query()->where('estado', 'abierto')->exists()) {
            throw new RuntimeException('Ya existe una caja abierta.');
        }

        $cashRegister = CorteCaja::query()->create([
            'user_id' => $this->operatorUserId(),
            'fecha_apertura' => now(),
            'monto_inicial' => (float) $payload['monto_inicial'],
            'estado' => 'abierto',
        ]);

        return [
            'status' => 'confirmed',
            'operacion' => 'abrir_caja',
            'caja' => [
                'id' => $cashRegister->id,
                'monto_inicial' => (float) $cashRegister->monto_inicial,
                'estado' => $cashRegister->estado,
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function prepareCloseCashRegister(float $countedAmount, ?string $notes = null): array
    {
        $summary = $this->cashSummary();

        if (! ($summary['caja']['abierta'] ?? false)) {
            return $this->operationBlocked('preparar_cerrar_caja', ['No hay caja abierta para cerrar.'], $summary);
        }

        if ($countedAmount < 0) {
            return $this->operationBlocked('preparar_cerrar_caja', ['El monto contado no puede ser negativo.'], $summary);
        }

        $expected = (float) $summary['caja']['monto_esperado_efectivo'];
        $closeSummary = [
            ...$summary,
            'monto_real_contado' => round($countedAmount, 2),
            'diferencia' => round($countedAmount - $expected, 2),
            'observaciones' => $notes,
        ];

        return $this->withConfirmationToken('cerrar_caja', [
            'corte_caja_id' => $summary['caja']['id'],
            'monto_real' => round($countedAmount, 2),
            'observaciones' => $notes,
        ], $closeSummary);
    }

    /**
     * @return array<string, mixed>
     */
    public function confirmCloseCashRegister(string $token): array
    {
        $confirmation = $this->pullConfirmation($token, 'cerrar_caja');
        $payload = $confirmation['payload'];

        $cashRegister = CorteCaja::query()
            ->with(['ventas', 'gastos'])
            ->whereKey($payload['corte_caja_id'])
            ->where('estado', 'abierto')
            ->firstOrFail();

        $paidSales = $cashRegister->ventas->where('estado', 'pagada');
        $cashSales = $this->sumSalesByPaymentMethod($paidSales, 'efectivo');
        $shiftExpenses = round((float) $cashRegister->gastos->where('origen', 'caja_dia')->sum('monto'), 2);
        $expected = round((float) $cashRegister->monto_inicial + $cashSales - $shiftExpenses, 2);
        $real = round((float) $payload['monto_real'], 2);

        $cashRegister->update([
            'fecha_cierre' => now(),
            'ventas_efectivo' => $cashSales,
            'ventas_tarjeta' => $this->sumSalesByPaymentMethod($paidSales, 'tarjeta'),
            'ventas_transferencia' => $this->sumSalesByPaymentMethod($paidSales, 'transferencia'),
            'gastos_turno' => $shiftExpenses,
            'monto_esperado' => $expected,
            'monto_real' => $real,
            'diferencia' => round($real - $expected, 2),
            'estado' => 'cerrado',
            'observaciones' => $payload['observaciones'] ?: null,
        ]);

        return [
            'status' => 'confirmed',
            'operacion' => 'cerrar_caja',
            'caja' => [
                'id' => $cashRegister->id,
                'monto_esperado' => (float) $cashRegister->monto_esperado,
                'monto_real' => (float) $cashRegister->monto_real,
                'diferencia' => (float) $cashRegister->diferencia,
                'estado' => $cashRegister->estado,
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function prepareInventoryMovement(
        int $inventoryItemId,
        string $movementType,
        float $quantity,
        ?float $unitCost = null,
        ?string $notes = null,
    ): array {
        if (! in_array($movementType, ['purchase', 'manual_in', 'manual_out', 'waste', 'return'], true)) {
            return $this->operationBlocked('preparar_movimiento_inventario', ['Tipo de movimiento no permitido para el asistente.']);
        }

        if ($quantity <= 0) {
            return $this->operationBlocked('preparar_movimiento_inventario', ['La cantidad debe ser mayor a cero.']);
        }

        if ($unitCost !== null && $unitCost < 0) {
            return $this->operationBlocked('preparar_movimiento_inventario', ['El costo unitario no puede ser negativo.']);
        }

        $item = InventoryItem::query()->with('unit')->whereKey($inventoryItemId)->first();

        if (! $item instanceof InventoryItem) {
            return $this->operationBlocked('preparar_movimiento_inventario', ['El item de inventario no existe.']);
        }

        $signedQuantity = in_array($movementType, ['purchase', 'manual_in', 'return'], true) ? $quantity : -$quantity;
        $stockAfter = round((float) $item->current_stock + $signedQuantity, 3);

        if ($stockAfter < 0) {
            return $this->operationBlocked('preparar_movimiento_inventario', ["Inventario insuficiente para {$item->name}."]);
        }

        $summary = [
            'item' => $this->inventoryItemPayload($item),
            'tipo_movimiento' => $movementType,
            'cantidad' => round($quantity, 3),
            'costo_unitario' => $unitCost !== null ? round($unitCost, 4) : null,
            'stock_despues' => $stockAfter,
            'notas' => $notes,
        ];

        return $this->withConfirmationToken('movimiento_inventario', [
            'inventory_item_id' => $inventoryItemId,
            'movement_type' => $movementType,
            'quantity' => round($quantity, 3),
            'unit_cost' => $unitCost !== null ? round($unitCost, 4) : null,
            'notes' => $notes,
        ], $summary);
    }

    /**
     * @return array<string, mixed>
     */
    public function confirmInventoryMovement(string $token): array
    {
        $confirmation = $this->pullConfirmation($token, 'movimiento_inventario');
        $payload = $confirmation['payload'];
        $item = InventoryItem::query()->findOrFail($payload['inventory_item_id']);

        $movement = $payload['movement_type'] === 'purchase'
            ? $this->inventoryEntryService->recordPurchase(
                inventoryItem: $item,
                quantity: (float) $payload['quantity'],
                unitCost: (float) ($payload['unit_cost'] ?? $item->average_cost),
                userId: $this->operatorUserId(),
                notes: $payload['notes'] ?? null,
            )
            : $this->inventoryMovementService->recordMovement(
                inventoryItem: $item,
                movementType: $payload['movement_type'],
                quantity: (float) $payload['quantity'],
                unitCost: $payload['unit_cost'] !== null ? (float) $payload['unit_cost'] : null,
                userId: $this->operatorUserId(),
                notes: $payload['notes'] ?? null,
            );

        return [
            'status' => 'confirmed',
            'operacion' => 'movimiento_inventario',
            'movimiento' => [
                'id' => $movement->id,
                'inventory_item_id' => $movement->inventory_item_id,
                'tipo' => $movement->movement_type,
                'cantidad' => (float) $movement->quantity,
                'stock_despues' => (float) $movement->stock_after,
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $payload
     * @param  array<string, mixed>  $summary
     * @return array<string, mixed>
     */
    private function withConfirmationToken(string $operation, array $payload, array $summary): array
    {
        $token = Str::random(48);
        $expiresAt = now()->addMinutes((int) config('mcp_operations.confirmation_ttl_minutes', 10));
        $fingerprint = $this->fingerprint($operation, $payload, $summary);

        Cache::put($this->cacheKey($token), [
            'operation' => $operation,
            'payload' => $payload,
            'summary' => $summary,
            'fingerprint' => $fingerprint,
            'user_id' => $this->operatorUserId(),
            'expires_at' => $expiresAt->toISOString(),
        ], $expiresAt);

        return [
            'status' => 'requires_confirmation',
            'operacion' => $operation,
            'confirmation_token' => $token,
            'expires_at' => $expiresAt->toISOString(),
            'fingerprint' => $fingerprint,
            'resumen' => $summary,
            'instruccion' => 'Muestra este resumen al usuario y llama la tool confirmar_* solo si confirma.',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function pullConfirmation(string $token, string $operation): array
    {
        $cacheKey = $this->cacheKey($token);
        $confirmation = Cache::get($cacheKey);

        if (! is_array($confirmation) || ($confirmation['operation'] ?? null) !== $operation) {
            throw new RuntimeException('Token de confirmacion invalido, vencido o ya utilizado.');
        }

        if (($confirmation['user_id'] ?? null) !== $this->operatorUserId()) {
            throw new RuntimeException('Token de confirmacion no corresponde al operador actual.');
        }

        Cache::forget($cacheKey);

        return $confirmation;
    }

    private function cacheKey(string $token): string
    {
        return "mcp_operations_confirmation:{$token}";
    }

    /**
     * @param  array<string, mixed>  $payload
     * @param  array<string, mixed>  $summary
     */
    private function fingerprint(string $operation, array $payload, array $summary): string
    {
        return hash('sha256', json_encode([
            'operation' => $operation,
            'payload' => $payload,
            'summary' => $summary,
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }

    /**
     * @param  array<int, string>  $errors
     * @param  array<string, mixed>  $context
     * @return array<string, mixed>
     */
    private function operationBlocked(string $operation, array $errors, array $context = []): array
    {
        return [
            'status' => 'blocked',
            'operacion' => $operation,
            'errores' => $errors,
            'contexto' => $context,
        ];
    }

    /**
     * @return Builder<InventoryItem>
     */
    private function inventoryItemsQuery()
    {
        return InventoryItem::query()
            ->with('unit')
            ->where('is_active', true)
            ->orderBy('name');
    }

    /**
     * @return array<string, mixed>
     */
    private function inventoryItemPayload(InventoryItem $item): array
    {
        return [
            'id' => $item->id,
            'nombre' => $item->name,
            'stock_actual' => (float) $item->current_stock,
            'stock_minimo' => (float) $item->minimum_stock,
            'unidad' => $item->unit?->abbreviation ?? $item->unit?->name ?? 'unidad',
            'costo_promedio' => (float) $item->average_cost,
            'bajo_inventario' => $this->isLowInventoryItem($item),
            'vendible' => (bool) $item->is_sellable,
            'consumible' => (bool) $item->is_consumable,
        ];
    }

    private function isLowInventoryItem(InventoryItem $item): bool
    {
        return (float) $item->minimum_stock > 0
            && (float) $item->current_stock <= (float) $item->minimum_stock;
    }

    /**
     * @return array<string, mixed>
     */
    private function productPayload(Producto $product): array
    {
        return [
            'id' => $product->id,
            'nombre' => $product->nombre,
            'categoria' => $product->categoria?->nombre,
            'precio_venta' => (float) $product->precio_venta,
            'costo_estimado' => (float) $product->costo_estimado,
            'tipo' => $product->product_type,
            'activo' => (bool) $product->activo,
            'requiere_opciones' => $product->product_type === 'configurable',
            'opciones' => $product->productOptionGroups
                ->map(fn ($group): array => [
                    'grupo_id' => $group->id,
                    'nombre' => $group->name,
                    'requeridas' => (float) $group->required_quantity,
                    'minimo' => (float) ($group->min_quantity ?? $group->required_quantity),
                    'maximo' => (float) ($group->max_quantity ?? $group->required_quantity),
                    'items' => $group->optionItems
                        ->where('is_active', true)
                        ->map(fn ($option): array => [
                            'option_item_id' => $option->id,
                            'inventory_item_id' => $option->inventory_item_id,
                            'nombre' => $option->inventoryItem?->name,
                            'cantidad_por_seleccion' => (float) $option->quantity_per_selection,
                            'extra_price' => (float) ($option->extra_price ?? 0),
                        ])
                        ->values()
                        ->all(),
                ])
                ->all(),
        ];
    }

    /**
     * @param  array<int, array<int, int|float>>  $selectedOptions
     * @return array<int, array<string, mixed>>
     */
    private function stockRequirementsForProduct(Producto $product, float $quantity, array $selectedOptions): array
    {
        if ($product->product_type === 'simple' && $product->inventoryItem instanceof InventoryItem) {
            return [$this->inventoryRequirement($product->inventoryItem, $quantity)];
        }

        if ($product->product_type === 'prepared' && $product->productRecipes->isNotEmpty()) {
            return $product->productRecipes
                ->map(fn ($recipe): array => $this->inventoryRequirement($recipe->inventoryItem, (float) $recipe->quantity * $quantity))
                ->all();
        }

        if ($product->product_type === 'configurable') {
            $components = $this->productConfigurationService->resolveConfiguration($product, $selectedOptions);

            return collect($components)
                ->map(function (array $component) use ($quantity): array {
                    $item = InventoryItem::query()->with('unit')->findOrFail($component['inventory_item_id']);

                    return $this->inventoryRequirement($item, (float) $component['quantity'] * $quantity);
                })
                ->all();
        }

        if ($product->insumos->isNotEmpty()) {
            return $product->insumos
                ->map(fn (Insumo $insumo): array => [
                    'origen' => 'legacy_insumo',
                    'id' => $insumo->id,
                    'nombre' => $insumo->nombre,
                    'unidad' => $insumo->unidad_medida,
                    'cantidad_requerida' => round((float) $insumo->pivot->cantidad_requerida * $quantity, 3),
                    'stock_actual' => (float) $insumo->cantidad_actual,
                    'suficiente' => (float) $insumo->cantidad_actual >= round((float) $insumo->pivot->cantidad_requerida * $quantity, 3),
                ])
                ->all();
        }

        throw new RuntimeException("El producto {$product->nombre} no tiene receta o inventario configurado.");
    }

    /**
     * @return array<string, mixed>
     */
    private function inventoryRequirement(InventoryItem $item, float $quantity): array
    {
        return [
            'origen' => 'inventory_item',
            'id' => $item->id,
            'nombre' => $item->name,
            'unidad' => $item->unit?->abbreviation ?? $item->unit?->name ?? 'unidad',
            'cantidad_requerida' => round($quantity, 3),
            'stock_actual' => (float) $item->current_stock,
            'suficiente' => (float) $item->current_stock >= round($quantity, 3),
        ];
    }

    /**
     * @param  array<int, array<string, mixed>>  $requirements
     * @return array<int, array<string, mixed>>
     */
    private function mergeStockRequirements(array $requirements): array
    {
        return collect($requirements)
            ->groupBy(fn (array $requirement): string => $requirement['origen'].':'.$requirement['id'])
            ->map(function (Collection $group): array {
                $first = $group->first();
                $required = round((float) $group->sum('cantidad_requerida'), 3);

                return [
                    ...$first,
                    'cantidad_requerida' => $required,
                    'suficiente' => (float) $first['stock_actual'] >= $required,
                ];
            })
            ->values()
            ->all();
    }

    /**
     * @param  Collection<int, Venta>  $sales
     */
    private function sumSalesByPaymentMethod(Collection $sales, string $paymentMethod): float
    {
        return round((float) $sales->where('metodo_pago', $paymentMethod)->sum('total'), 2);
    }

    private function operatorUserId(): ?int
    {
        return auth()->id() ?? (config('mcp_operations.operator_user_id') ? (int) config('mcp_operations.operator_user_id') : null);
    }
}

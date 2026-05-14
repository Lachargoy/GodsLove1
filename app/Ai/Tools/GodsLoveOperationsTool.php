<?php

namespace App\Ai\Tools;

use App\Services\Mcp\OperationsAssistantService;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;
use Stringable;
use Throwable;

class GodsLoveOperationsTool implements Tool
{
    public function __construct(
        private readonly OperationsAssistantService $operations,
    ) {}

    public function name(): string
    {
        return 'operacion_godslove';
    }

    public function description(): Stringable|string
    {
        return <<<'TEXT'
Ejecuta operaciones permitidas de GodsLove sin SQL libre. Acciones disponibles:
consultar_inventario, buscar_producto, resumen_caja, estimar_venta,
preparar_venta, confirmar_venta, preparar_abrir_caja, confirmar_abrir_caja,
preparar_cerrar_caja, confirmar_cerrar_caja, preparar_movimiento_inventario,
confirmar_movimiento_inventario. Las acciones preparar_* no guardan datos y devuelven confirmation_token.
Las acciones confirmar_* si modifican datos y requieren confirmation_token.
TEXT;
    }

    public function handle(Request $request): Stringable|string
    {
        $arguments = $request->all();

        try {
            $result = match ($request->string('action')->toString()) {
                'consultar_inventario' => $this->operations->inventorySnapshot(
                    search: data_get($arguments, 'search'),
                    onlyLow: $request->boolean('only_low'),
                ),
                'buscar_producto' => $this->operations->searchProducts(
                    search: data_get($arguments, 'search'),
                    categoryId: $request->filled('categoria_producto_id') ? $request->integer('categoria_producto_id') : null,
                    activeOnly: ! $request->has('active_only') || $request->boolean('active_only'),
                ),
                'resumen_caja' => $this->operations->cashSummary(),
                'estimar_venta' => $this->operations->estimateSale(
                    items: $request->array('items'),
                    discount: (float) data_get($arguments, 'descuento', 0),
                    paymentMethod: (string) data_get($arguments, 'metodo_pago', 'efectivo'),
                ),
                'preparar_venta' => $this->operations->prepareSale(
                    items: $request->array('items'),
                    discount: (float) data_get($arguments, 'descuento', 0),
                    paymentMethod: (string) data_get($arguments, 'metodo_pago', 'efectivo'),
                ),
                'confirmar_venta' => $this->operations->confirmSale((string) data_get($arguments, 'confirmation_token')),
                'preparar_abrir_caja' => $this->operations->prepareOpenCashRegister((float) data_get($arguments, 'monto_inicial', -1)),
                'confirmar_abrir_caja' => $this->operations->confirmOpenCashRegister((string) data_get($arguments, 'confirmation_token')),
                'preparar_cerrar_caja' => $this->operations->prepareCloseCashRegister(
                    countedAmount: (float) data_get($arguments, 'monto_real', -1),
                    notes: data_get($arguments, 'observaciones'),
                ),
                'confirmar_cerrar_caja' => $this->operations->confirmCloseCashRegister((string) data_get($arguments, 'confirmation_token')),
                'preparar_movimiento_inventario' => $this->operations->prepareInventoryMovement(
                    inventoryItemId: $request->integer('inventory_item_id'),
                    movementType: (string) data_get($arguments, 'movement_type'),
                    quantity: (float) data_get($arguments, 'quantity', 0),
                    unitCost: $request->filled('unit_cost') ? (float) data_get($arguments, 'unit_cost') : null,
                    notes: data_get($arguments, 'notes'),
                ),
                'confirmar_movimiento_inventario' => $this->operations->confirmInventoryMovement((string) data_get($arguments, 'confirmation_token')),
                default => [
                    'status' => 'error',
                    'error' => 'Accion no permitida para el asistente.',
                ],
            };
        } catch (Throwable $throwable) {
            $result = [
                'status' => 'error',
                'error' => $throwable->getMessage(),
            ];
        }

        return json_encode($result, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: '{"status":"error"}';
    }

    /**
     * @return array<string, mixed>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'action' => $schema->string()
                ->enum([
                    'consultar_inventario',
                    'buscar_producto',
                    'resumen_caja',
                    'estimar_venta',
                    'preparar_venta',
                    'confirmar_venta',
                    'preparar_abrir_caja',
                    'confirmar_abrir_caja',
                    'preparar_cerrar_caja',
                    'confirmar_cerrar_caja',
                    'preparar_movimiento_inventario',
                    'confirmar_movimiento_inventario',
                ])
                ->description('Operacion permitida a ejecutar.')
                ->required(),
            'search' => $schema->string()->description('Texto de busqueda para inventario o productos.')->nullable(),
            'only_low' => $schema->boolean()->description('Solo inventario bajo.')->nullable(),
            'categoria_producto_id' => $schema->integer()->description('Filtro opcional de categoria de producto.')->nullable(),
            'active_only' => $schema->boolean()->description('Solo productos activos.')->nullable(),
            'items' => $schema->array()
                ->items($schema->object([
                    'producto_id' => $schema->integer()->required(),
                    'cantidad' => $schema->number()->required(),
                    'selected_options' => $schema->array()->items($schema->integer())->nullable(),
                ])->withoutAdditionalProperties())
                ->description('Lineas de venta con producto_id, cantidad y opciones si aplica.')
                ->nullable(),
            'descuento' => $schema->number()->description('Descuento de venta.')->nullable(),
            'metodo_pago' => $schema->string()->enum(['efectivo', 'tarjeta', 'transferencia', 'mixto'])->nullable(),
            'confirmation_token' => $schema->string()->description('Token devuelto por una accion preparar_*.')->nullable(),
            'monto_inicial' => $schema->number()->description('Monto inicial para abrir caja.')->nullable(),
            'monto_real' => $schema->number()->description('Monto contado para cerrar caja.')->nullable(),
            'observaciones' => $schema->string()->description('Notas de cierre de caja.')->nullable(),
            'inventory_item_id' => $schema->integer()->description('ID del item de inventario.')->nullable(),
            'movement_type' => $schema->string()->enum(['purchase', 'manual_in', 'manual_out', 'waste', 'return'])->nullable(),
            'quantity' => $schema->number()->description('Cantidad del movimiento de inventario.')->nullable(),
            'unit_cost' => $schema->number()->description('Costo unitario opcional.')->nullable(),
            'notes' => $schema->string()->description('Notas del movimiento de inventario.')->nullable(),
        ];
    }
}

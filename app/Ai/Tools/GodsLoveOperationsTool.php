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
confirmar_movimiento_inventario, preparar_alta_insumo, confirmar_alta_insumo,
preparar_alta_categoria, confirmar_alta_categoria, preparar_alta_producto,
confirmar_alta_producto, preparar_receta_producto, confirmar_receta_producto,
preparar_opciones_producto, confirmar_opciones_producto.
Las acciones preparar_* no guardan datos y devuelven confirmation_token.
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
                'preparar_alta_insumo' => $this->operations->prepareCreateInsumo(
                    name: (string) data_get($arguments, 'nombre'),
                    categoryId: $request->filled('categoria_insumo_id') ? $request->integer('categoria_insumo_id') : null,
                    unitName: (string) data_get($arguments, 'unidad_medida', 'pieza'),
                    currentStock: (float) data_get($arguments, 'cantidad_actual', 0),
                    minimumStock: (float) data_get($arguments, 'cantidad_minima', 0),
                    unitCost: (float) data_get($arguments, 'costo_unitario', 0),
                    isSellable: $request->boolean('vendible_directo'),
                ),
                'confirmar_alta_insumo' => $this->operations->confirmCreateInsumo((string) data_get($arguments, 'confirmation_token')),
                'preparar_alta_categoria' => $this->operations->prepareCreateCategory(
                    type: (string) data_get($arguments, 'tipo'),
                    name: (string) data_get($arguments, 'nombre'),
                    description: data_get($arguments, 'descripcion'),
                ),
                'confirmar_alta_categoria' => $this->operations->confirmCreateCategory((string) data_get($arguments, 'confirmation_token')),
                'preparar_alta_producto' => $this->operations->prepareCreateProduct(
                    name: (string) data_get($arguments, 'nombre'),
                    categoryId: $request->filled('categoria_producto_id') ? $request->integer('categoria_producto_id') : null,
                    price: (float) data_get($arguments, 'precio_venta', 0),
                    description: data_get($arguments, 'descripcion'),
                    estimatedCost: $request->filled('costo_estimado') ? (float) data_get($arguments, 'costo_estimado') : null,
                    productType: (string) data_get($arguments, 'product_type', 'prepared'),
                    inventoryItemId: $request->filled('inventory_item_id') ? $request->integer('inventory_item_id') : null,
                    optionGroupName: data_get($arguments, 'option_group_name'),
                    requiredQuantity: $request->filled('required_quantity') ? (float) data_get($arguments, 'required_quantity') : null,
                ),
                'confirmar_alta_producto' => $this->operations->confirmCreateProduct((string) data_get($arguments, 'confirmation_token')),
                'preparar_receta_producto' => $this->operations->prepareProductRecipe(
                    productId: $request->integer('producto_id'),
                    items: $request->array('recipe_items'),
                    replace: ! $request->has('replace') || $request->boolean('replace'),
                ),
                'confirmar_receta_producto' => $this->operations->confirmProductRecipe((string) data_get($arguments, 'confirmation_token')),
                'preparar_opciones_producto' => $this->operations->prepareProductOptions(
                    productId: $request->integer('producto_id'),
                    groupName: (string) data_get($arguments, 'group_name', 'Sabores'),
                    requiredQuantity: (float) data_get($arguments, 'required_quantity', 1),
                    minQuantity: $request->filled('min_quantity') ? (float) data_get($arguments, 'min_quantity') : null,
                    maxQuantity: $request->filled('max_quantity') ? (float) data_get($arguments, 'max_quantity') : null,
                    options: $request->array('options'),
                ),
                'confirmar_opciones_producto' => $this->operations->confirmProductOptions((string) data_get($arguments, 'confirmation_token')),
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
                    'preparar_alta_insumo',
                    'confirmar_alta_insumo',
                    'preparar_alta_categoria',
                    'confirmar_alta_categoria',
                    'preparar_alta_producto',
                    'confirmar_alta_producto',
                    'preparar_receta_producto',
                    'confirmar_receta_producto',
                    'preparar_opciones_producto',
                    'confirmar_opciones_producto',
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
            'nombre' => $schema->string()->description('Nombre del nuevo insumo.')->nullable(),
            'tipo' => $schema->string()->enum(['producto', 'insumo', 'gasto'])->description('Tipo de categoria cuando action=preparar_alta_categoria.')->nullable(),
            'descripcion' => $schema->string()->description('Descripcion opcional de categoria o producto.')->nullable(),
            'categoria_insumo_id' => $schema->integer()->description('ID de categoria de insumo opcional.')->nullable(),
            'unidad_medida' => $schema->string()->description('Unidad del nuevo insumo: pieza, kg, g, litro, ml, etc.')->nullable(),
            'cantidad_actual' => $schema->number()->description('Stock inicial del nuevo insumo.')->nullable(),
            'cantidad_minima' => $schema->number()->description('Stock minimo del nuevo insumo.')->nullable(),
            'costo_unitario' => $schema->number()->description('Costo unitario inicial del nuevo insumo.')->nullable(),
            'vendible_directo' => $schema->boolean()->description('true si este insumo tambien se vende directo como producto unico.')->nullable(),
            'precio_venta' => $schema->number()->description('Precio de venta para preparar_alta_producto.')->nullable(),
            'costo_estimado' => $schema->number()->description('Costo estimado para preparar_alta_producto.')->nullable(),
            'product_type' => $schema->string()->enum(['simple', 'prepared', 'configurable'])->description('Tipo de producto.')->nullable(),
            'option_group_name' => $schema->string()->description('Grupo inicial si product_type=configurable.')->nullable(),
            'producto_id' => $schema->integer()->description('Producto a configurar para receta u opciones.')->nullable(),
            'recipe_items' => $schema->array()
                ->items($schema->object([
                    'insumo_id' => $schema->integer()->required(),
                    'cantidad_requerida' => $schema->number()->required(),
                ])->withoutAdditionalProperties())
                ->description('Lineas para preparar_receta_producto.')
                ->nullable(),
            'replace' => $schema->boolean()->description('true reemplaza la receta completa.')->nullable(),
            'group_name' => $schema->string()->description('Nombre del grupo configurable para preparar_opciones_producto.')->nullable(),
            'required_quantity' => $schema->number()->description('Cantidad requerida en grupo configurable.')->nullable(),
            'min_quantity' => $schema->number()->description('Minimo en grupo configurable.')->nullable(),
            'max_quantity' => $schema->number()->description('Maximo en grupo configurable.')->nullable(),
            'options' => $schema->array()
                ->items($schema->object([
                    'inventory_item_id' => $schema->integer()->required(),
                    'quantity_per_selection' => $schema->number()->required(),
                    'extra_price' => $schema->number()->nullable(),
                ])->withoutAdditionalProperties())
                ->description('Opciones para preparar_opciones_producto.')
                ->nullable(),
        ];
    }
}

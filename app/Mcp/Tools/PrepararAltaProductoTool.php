<?php

namespace App\Mcp\Tools;

use App\Mcp\Tools\Concerns\RespondsWithOperations;
use App\Services\Mcp\OperationsAssistantService;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Attributes\Title;
use Laravel\Mcp\Server\Tool;

#[Name('preparar_alta_producto')]
#[Title('Preparar alta de producto')]
#[Description('Prepara un producto simple, preparado o configurable. No crea registros hasta confirmar. Para simple usa inventory_item_id o auto_create_inventory_item para crear el item de inventario al confirmar; para configurable requiere grupo y cantidad requerida.')]
class PrepararAltaProductoTool extends Tool
{
    use RespondsWithOperations;

    public function handle(Request $request, OperationsAssistantService $operations): ResponseFactory
    {
        $validated = $request->validate([
            'nombre' => ['required', 'string', 'max:255'],
            'categoria_producto_id' => ['nullable', 'integer', 'exists:categoria_productos,id'],
            'categoria_producto_nombre' => ['nullable', 'string', 'max:255'],
            'descripcion' => ['nullable', 'string'],
            'precio_venta' => ['required', 'numeric', 'min:0'],
            'costo_estimado' => ['nullable', 'numeric', 'min:0'],
            'product_type' => ['required', 'in:simple,prepared,configurable'],
            'inventory_item_id' => ['nullable', 'integer', 'exists:inventory_items,id'],
            'auto_create_inventory_item' => ['nullable', 'boolean'],
            'stock_inicial' => ['nullable', 'numeric', 'min:0'],
            'unidad_medida' => ['nullable', 'string', 'max:40'],
            'option_group_name' => ['nullable', 'string', 'max:255'],
            'required_quantity' => ['nullable', 'numeric', 'min:0.001'],
        ]);

        return $this->operationResponse(fn (): array => $operations->prepareCreateProduct(
            name: $validated['nombre'],
            categoryId: isset($validated['categoria_producto_id']) ? (int) $validated['categoria_producto_id'] : null,
            price: (float) $validated['precio_venta'],
            description: $validated['descripcion'] ?? null,
            estimatedCost: isset($validated['costo_estimado']) ? (float) $validated['costo_estimado'] : null,
            productType: $validated['product_type'],
            inventoryItemId: isset($validated['inventory_item_id']) ? (int) $validated['inventory_item_id'] : null,
            optionGroupName: $validated['option_group_name'] ?? null,
            requiredQuantity: isset($validated['required_quantity']) ? (float) $validated['required_quantity'] : null,
            categoryName: $validated['categoria_producto_nombre'] ?? null,
            autoCreateInventoryItem: (bool) ($validated['auto_create_inventory_item'] ?? false),
            initialStock: isset($validated['stock_inicial']) ? (float) $validated['stock_inicial'] : null,
            unitName: $validated['unidad_medida'] ?? null,
        ));
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'nombre' => $schema->string()->description('Nombre del producto nuevo.')->required(),
            'categoria_producto_id' => $schema->integer()->description('Categoria de producto opcional.'),
            'categoria_producto_nombre' => $schema->string()->description('Nombre de categoria de producto si no se conoce el ID.'),
            'descripcion' => $schema->string()->description('Descripcion opcional.'),
            'precio_venta' => $schema->number()->description('Precio de venta.')->required(),
            'costo_estimado' => $schema->number()->description('Costo estimado opcional.'),
            'product_type' => $schema->string()->enum(['simple', 'prepared', 'configurable'])->description('Tipo de producto.')->required(),
            'inventory_item_id' => $schema->integer()->description('Item de inventario requerido si product_type=simple.'),
            'auto_create_inventory_item' => $schema->boolean()->description('Si true y product_type=simple, crea tambien el item de inventario al confirmar.'),
            'stock_inicial' => $schema->number()->description('Stock inicial del item auto-creado.'),
            'unidad_medida' => $schema->string()->description('Unidad del item auto-creado, por defecto pieza.'),
            'option_group_name' => $schema->string()->description('Nombre de grupo requerido si product_type=configurable.'),
            'required_quantity' => $schema->number()->description('Cantidad requerida del grupo configurable.'),
        ];
    }
}

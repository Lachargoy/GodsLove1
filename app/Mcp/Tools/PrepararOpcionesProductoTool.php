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

#[Name('preparar_opciones_producto')]
#[Title('Preparar opciones de producto')]
#[Description('Prepara un grupo configurable de sabores/opciones para un producto. No guarda hasta confirmar.')]
class PrepararOpcionesProductoTool extends Tool
{
    use RespondsWithOperations;

    public function handle(Request $request, OperationsAssistantService $operations): ResponseFactory
    {
        $validated = $request->validate([
            'producto_id' => ['required', 'integer', 'exists:productos,id'],
            'group_name' => ['required', 'string', 'max:255'],
            'required_quantity' => ['required', 'numeric', 'min:0.001'],
            'min_quantity' => ['nullable', 'numeric', 'min:0'],
            'max_quantity' => ['nullable', 'numeric', 'min:0.001'],
            'options' => ['required', 'array', 'min:1'],
            'options.*.inventory_item_id' => ['required', 'integer', 'exists:inventory_items,id'],
            'options.*.quantity_per_selection' => ['required', 'numeric', 'min:0.001'],
            'options.*.extra_price' => ['nullable', 'numeric', 'min:0'],
        ]);

        return $this->operationResponse(fn (): array => $operations->prepareProductOptions(
            productId: (int) $validated['producto_id'],
            groupName: $validated['group_name'],
            requiredQuantity: (float) $validated['required_quantity'],
            minQuantity: isset($validated['min_quantity']) ? (float) $validated['min_quantity'] : null,
            maxQuantity: isset($validated['max_quantity']) ? (float) $validated['max_quantity'] : null,
            options: $validated['options'],
        ));
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'producto_id' => $schema->integer()->description('Producto configurable.')->required(),
            'group_name' => $schema->string()->description('Nombre del grupo, por ejemplo Sabores.')->required(),
            'required_quantity' => $schema->number()->description('Cantidad requerida por venta.')->required(),
            'min_quantity' => $schema->number()->description('Minimo permitido.'),
            'max_quantity' => $schema->number()->description('Maximo permitido.'),
            'options' => $schema->array()
                ->items($schema->object([
                    'inventory_item_id' => $schema->integer()->required(),
                    'quantity_per_selection' => $schema->number()->required(),
                    'extra_price' => $schema->number()->nullable(),
                ])->withoutAdditionalProperties())
                ->description('Opciones con item de inventario, cantidad que descuenta por seleccion y precio extra opcional.')
                ->required(),
        ];
    }
}

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

#[Name('preparar_receta_producto')]
#[Title('Preparar receta de producto')]
#[Description('Prepara la receta de un producto con insumos y cantidades. Por defecto reemplaza la receta completa. No guarda hasta confirmar.')]
class PrepararRecetaProductoTool extends Tool
{
    use RespondsWithOperations;

    public function handle(Request $request, OperationsAssistantService $operations): ResponseFactory
    {
        $validated = $request->validate([
            'producto_id' => ['required', 'integer', 'exists:productos,id'],
            'items' => ['required', 'array', 'min:1'],
            'items.*.insumo_id' => ['required', 'integer', 'exists:insumos,id'],
            'items.*.cantidad_requerida' => ['required', 'numeric', 'min:0.001'],
            'replace' => ['nullable', 'boolean'],
        ]);

        return $this->operationResponse(fn (): array => $operations->prepareProductRecipe(
            productId: (int) $validated['producto_id'],
            items: $validated['items'],
            replace: (bool) ($validated['replace'] ?? true),
        ));
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'producto_id' => $schema->integer()->description('Producto a configurar.')->required(),
            'items' => $schema->array()
                ->items($schema->object([
                    'insumo_id' => $schema->integer()->required(),
                    'cantidad_requerida' => $schema->number()->required(),
                ])->withoutAdditionalProperties())
                ->description('Lineas de receta: insumo_id y cantidad_requerida.')
                ->required(),
            'replace' => $schema->boolean()->description('true reemplaza la receta completa; false agrega o actualiza lineas.'),
        ];
    }
}

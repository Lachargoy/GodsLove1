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
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[Name('buscar_producto')]
#[Title('Buscar producto')]
#[Description('Busca productos del catalogo para ventas. No modifica datos. Devuelve precio, tipo, categoria y grupos/opciones cuando el producto es configurable. Usa esta tool antes de estimar o preparar ventas; no inventes precios.')]
#[IsReadOnly]
class BuscarProductoTool extends Tool
{
    use RespondsWithOperations;

    public function handle(Request $request, OperationsAssistantService $operations): ResponseFactory
    {
        $validated = $request->validate([
            'search' => ['nullable', 'string'],
            'category_id' => ['nullable', 'integer', 'exists:categoria_productos,id'],
            'active_only' => ['nullable', 'boolean'],
        ]);

        return $this->operationResponse(fn (): array => $operations->searchProducts(
            search: $validated['search'] ?? null,
            categoryId: $validated['category_id'] ?? null,
            activeOnly: (bool) ($validated['active_only'] ?? true),
        ));
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'search' => $schema->string()->description('Nombre o parte del nombre del producto.'),
            'category_id' => $schema->integer()->description('ID opcional de categoria de producto.'),
            'active_only' => $schema->boolean()->description('true para buscar solo productos activos.')->default(true),
        ];
    }
}

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

#[Name('consultar_inventario')]
#[Title('Consultar inventario')]
#[Description('Consulta stock de inventario activo. No modifica datos. Usa search para filtrar por nombre y only_low para pedir solo inventario bajo. Responde items con stock_actual, stock_minimo, unidad, costo_promedio y bajo_inventario.')]
#[IsReadOnly]
class ConsultarInventarioTool extends Tool
{
    use RespondsWithOperations;

    public function handle(Request $request, OperationsAssistantService $operations): ResponseFactory
    {
        $validated = $request->validate([
            'search' => ['nullable', 'string'],
            'only_low' => ['nullable', 'boolean'],
        ]);

        return $this->operationResponse(fn (): array => $operations->inventorySnapshot(
            search: $validated['search'] ?? null,
            onlyLow: (bool) ($validated['only_low'] ?? false),
        ));
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'search' => $schema->string()->description('Texto opcional para filtrar insumos o items de inventario por nombre.'),
            'only_low' => $schema->boolean()->description('true para devolver solamente inventario bajo.')->default(false),
        ];
    }

    public function outputSchema(JsonSchema $schema): array
    {
        return [
            'total_items' => $schema->integer()->required(),
            'solo_bajo_inventario' => $schema->boolean()->required(),
            'items' => $schema->array()->required(),
        ];
    }
}

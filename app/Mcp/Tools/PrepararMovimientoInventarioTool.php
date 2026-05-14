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

#[Name('preparar_movimiento_inventario')]
#[Title('Preparar movimiento inventario')]
#[Description('Prepara entrada, salida, merma o devolucion de inventario y devuelve confirmation_token. No cambia stock hasta confirmar. Tipos permitidos: purchase, manual_in, manual_out, waste, return.')]
class PrepararMovimientoInventarioTool extends Tool
{
    use RespondsWithOperations;

    public function handle(Request $request, OperationsAssistantService $operations): ResponseFactory
    {
        $validated = $request->validate([
            'inventory_item_id' => ['required', 'integer', 'exists:inventory_items,id'],
            'movement_type' => ['required', 'in:purchase,manual_in,manual_out,waste,return'],
            'quantity' => ['required', 'numeric', 'min:0.001'],
            'unit_cost' => ['nullable', 'numeric', 'min:0'],
            'notes' => ['nullable', 'string'],
        ]);

        return $this->operationResponse(fn (): array => $operations->prepareInventoryMovement(
            inventoryItemId: (int) $validated['inventory_item_id'],
            movementType: $validated['movement_type'],
            quantity: (float) $validated['quantity'],
            unitCost: array_key_exists('unit_cost', $validated) ? (float) $validated['unit_cost'] : null,
            notes: $validated['notes'] ?? null,
        ));
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'inventory_item_id' => $schema->integer()->required(),
            'movement_type' => $schema->string()->enum(['purchase', 'manual_in', 'manual_out', 'waste', 'return'])->required(),
            'quantity' => $schema->number()->required(),
            'unit_cost' => $schema->number()->description('Costo unitario; requerido semanticamente para purchase si se conoce.'),
            'notes' => $schema->string()->description('Motivo o nota del movimiento.'),
        ];
    }
}

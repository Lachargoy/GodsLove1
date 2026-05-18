<?php

namespace App\Mcp\Tools;

use App\Mcp\Tools\Concerns\RespondsWithOperations;
use App\Services\Mcp\OperationsAssistantService;
use Laravel\Mcp\Request;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Attributes\Title;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[Name('consultar_ventas')]
#[Title('Consultar ventas')]
#[Description('Consulta que se ha vendido con desglose por producto, metodo de pago, ticket y componentes de inventario consumidos. No modifica datos. Por defecto usa la caja abierta; si no hay caja abierta usa el dia actual.')]
#[IsReadOnly]
class ConsultarVentasTool extends Tool
{
    use RespondsWithOperations;

    public function handle(Request $request, OperationsAssistantService $operations): ResponseFactory
    {
        $validated = $request->validate([
            'fecha_inicio' => ['nullable', 'date'],
            'fecha_fin' => ['nullable', 'date'],
            'corte_caja_id' => ['nullable', 'integer', 'exists:corte_cajas,id'],
            'limit' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        return $this->operationResponse(fn (): array => $operations->salesBreakdown(
            dateFrom: $validated['fecha_inicio'] ?? null,
            dateTo: $validated['fecha_fin'] ?? null,
            cashRegisterId: $validated['corte_caja_id'] ?? null,
            limit: $validated['limit'] ?? 25,
        ));
    }
}

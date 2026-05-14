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

#[Name('resumen_caja')]
#[Title('Resumen de caja')]
#[Description('Consulta caja abierta, ventas por metodo, gastos del turno, efectivo esperado y total de tickets. No modifica datos. Usala antes de abrir, cerrar o registrar ventas.')]
#[IsReadOnly]
class ResumenCajaTool extends Tool
{
    use RespondsWithOperations;

    public function handle(Request $request, OperationsAssistantService $operations): ResponseFactory
    {
        return $this->operationResponse(fn (): array => $operations->cashSummary());
    }
}

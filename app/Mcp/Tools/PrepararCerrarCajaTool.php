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

#[Name('preparar_cerrar_caja')]
#[Title('Preparar cerrar caja')]
#[Description('Prepara el cierre de caja con monto real contado. Devuelve expected cash, diferencia y confirmation_token. No cierra caja hasta confirmar.')]
class PrepararCerrarCajaTool extends Tool
{
    use RespondsWithOperations;

    public function handle(Request $request, OperationsAssistantService $operations): ResponseFactory
    {
        $validated = $request->validate([
            'monto_real' => ['required', 'numeric', 'min:0'],
            'observaciones' => ['nullable', 'string'],
        ]);

        return $this->operationResponse(fn (): array => $operations->prepareCloseCashRegister(
            countedAmount: (float) $validated['monto_real'],
            notes: $validated['observaciones'] ?? null,
        ));
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'monto_real' => $schema->number()->description('Efectivo contado al cierre.')->required(),
            'observaciones' => $schema->string()->description('Notas opcionales del cierre.'),
        ];
    }
}

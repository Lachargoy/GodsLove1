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
use Laravel\Mcp\Server\Tools\Annotations\IsDestructive;

#[Name('confirmar_cerrar_caja')]
#[Title('Confirmar cerrar caja')]
#[Description('Cierra una caja abierta usando confirmation_token de preparar_cerrar_caja. Modifica corte, totales, monto real y diferencia.')]
#[IsDestructive]
class ConfirmarCerrarCajaTool extends Tool
{
    use RespondsWithOperations;

    public function handle(Request $request, OperationsAssistantService $operations): ResponseFactory
    {
        $validated = $request->validate([
            'confirmation_token' => ['required', 'string'],
        ]);

        return $this->operationResponse(fn (): array => $operations->confirmCloseCashRegister($validated['confirmation_token']));
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'confirmation_token' => $schema->string()->required(),
        ];
    }
}

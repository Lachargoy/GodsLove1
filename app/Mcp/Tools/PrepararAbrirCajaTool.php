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

#[Name('preparar_abrir_caja')]
#[Title('Preparar abrir caja')]
#[Description('Prepara apertura de caja con monto inicial y devuelve confirmation_token. No crea la caja hasta llamar confirmar_abrir_caja tras confirmacion del usuario.')]
class PrepararAbrirCajaTool extends Tool
{
    use RespondsWithOperations;

    public function handle(Request $request, OperationsAssistantService $operations): ResponseFactory
    {
        $validated = $request->validate([
            'monto_inicial' => ['required', 'numeric', 'min:0'],
        ]);

        return $this->operationResponse(fn (): array => $operations->prepareOpenCashRegister((float) $validated['monto_inicial']));
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'monto_inicial' => $schema->number()->description('Efectivo inicial con que abre el turno.')->required(),
        ];
    }
}

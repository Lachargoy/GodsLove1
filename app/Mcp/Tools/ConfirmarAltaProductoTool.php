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

#[Name('confirmar_alta_producto')]
#[Title('Confirmar alta de producto')]
#[Description('Confirma un producto preparado y crea el registro. Solo usar tras confirmacion explicita del usuario.')]
#[IsDestructive]
class ConfirmarAltaProductoTool extends Tool
{
    use RespondsWithOperations;

    public function handle(Request $request, OperationsAssistantService $operations): ResponseFactory
    {
        $validated = $request->validate([
            'confirmation_token' => ['required', 'string'],
        ]);

        return $this->operationResponse(fn (): array => $operations->confirmCreateProduct($validated['confirmation_token']));
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'confirmation_token' => $schema->string()->description('Token devuelto por preparar_alta_producto.')->required(),
        ];
    }
}

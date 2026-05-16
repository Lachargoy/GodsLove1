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

#[Name('preparar_alta_categoria')]
#[Title('Preparar alta de categoria')]
#[Description('Prepara una nueva categoria de producto, insumo o gasto. No crea registros hasta confirmar. Devuelve confirmation_token.')]
class PrepararAltaCategoriaTool extends Tool
{
    use RespondsWithOperations;

    public function handle(Request $request, OperationsAssistantService $operations): ResponseFactory
    {
        $validated = $request->validate([
            'tipo' => ['required', 'in:producto,insumo,gasto'],
            'nombre' => ['required', 'string', 'max:255'],
            'descripcion' => ['nullable', 'string'],
        ]);

        return $this->operationResponse(fn (): array => $operations->prepareCreateCategory(
            type: $validated['tipo'],
            name: $validated['nombre'],
            description: $validated['descripcion'] ?? null,
        ));
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'tipo' => $schema->string()->enum(['producto', 'insumo', 'gasto'])->description('Tipo de categoria a crear.')->required(),
            'nombre' => $schema->string()->description('Nombre de la categoria nueva.')->required(),
            'descripcion' => $schema->string()->description('Descripcion opcional.'),
        ];
    }
}

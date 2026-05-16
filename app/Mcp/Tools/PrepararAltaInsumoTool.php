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

#[Name('preparar_alta_insumo')]
#[Title('Preparar alta de insumo')]
#[Description('Prepara el alta de un nuevo insumo y su item de inventario ligado. No crea registros hasta confirmar. Requiere nombre, unidad, stock inicial, minimo y costo unitario. Devuelve confirmation_token.')]
class PrepararAltaInsumoTool extends Tool
{
    use RespondsWithOperations;

    public function handle(Request $request, OperationsAssistantService $operations): ResponseFactory
    {
        $validated = $request->validate([
            'nombre' => ['required', 'string', 'max:255'],
            'categoria_insumo_id' => ['nullable', 'integer', 'exists:categoria_insumos,id'],
            'unidad_medida' => ['required', 'string', 'max:50'],
            'cantidad_actual' => ['required', 'numeric', 'min:0'],
            'cantidad_minima' => ['required', 'numeric', 'min:0'],
            'costo_unitario' => ['required', 'numeric', 'min:0'],
            'vendible_directo' => ['nullable', 'boolean'],
        ]);

        return $this->operationResponse(fn (): array => $operations->prepareCreateInsumo(
            name: $validated['nombre'],
            categoryId: isset($validated['categoria_insumo_id']) ? (int) $validated['categoria_insumo_id'] : null,
            unitName: $validated['unidad_medida'],
            currentStock: (float) $validated['cantidad_actual'],
            minimumStock: (float) $validated['cantidad_minima'],
            unitCost: (float) $validated['costo_unitario'],
            isSellable: (bool) ($validated['vendible_directo'] ?? false),
        ));
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'nombre' => $schema->string()->description('Nombre del insumo nuevo.')->required(),
            'categoria_insumo_id' => $schema->integer()->description('Categoria de insumo opcional.'),
            'unidad_medida' => $schema->string()->description('Unidad: pieza, kg, g, litro, ml, etc.')->required(),
            'cantidad_actual' => $schema->number()->description('Stock inicial.')->required(),
            'cantidad_minima' => $schema->number()->description('Stock minimo.')->required(),
            'costo_unitario' => $schema->number()->description('Costo unitario inicial.')->required(),
            'vendible_directo' => $schema->boolean()->description('true si tambien se vende directo como producto unico.'),
        ];
    }
}

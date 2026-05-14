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

#[Name('estimar_venta')]
#[Title('Estimar venta')]
#[Description('Calcula subtotal, descuento, total e impacto de inventario sin guardar nada. Requiere items con producto_id y cantidad. Para productos configurables incluye selected_options como {group_id: {option_item_id: cantidad}}.')]
#[IsReadOnly]
class EstimarVentaTool extends Tool
{
    use RespondsWithOperations;

    public function handle(Request $request, OperationsAssistantService $operations): ResponseFactory
    {
        $validated = $request->validate([
            'items' => ['required', 'array', 'min:1'],
            'items.*.producto_id' => ['required', 'integer'],
            'items.*.cantidad' => ['required', 'numeric', 'min:0.001'],
            'items.*.selected_options' => ['nullable', 'array'],
            'descuento' => ['nullable', 'numeric', 'min:0'],
            'metodo_pago' => ['nullable', 'in:efectivo,tarjeta,transferencia,mixto'],
        ]);

        return $this->operationResponse(fn (): array => $operations->estimateSale(
            items: $validated['items'],
            discount: (float) ($validated['descuento'] ?? 0),
            paymentMethod: $validated['metodo_pago'] ?? 'efectivo',
        ));
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'items' => $schema->array()
                ->items($schema->object([
                    'producto_id' => $schema->integer()->description('ID del producto activo.')->required(),
                    'cantidad' => $schema->number()->description('Cantidad a vender.')->required(),
                    'selected_options' => $schema->object()->description('Opciones para configurables: {group_id: {option_item_id: cantidad}}.'),
                ]))
                ->description('Lineas de venta.')
                ->required(),
            'descuento' => $schema->number()->description('Descuento monetario opcional.')->default(0),
            'metodo_pago' => $schema->string()->enum(['efectivo', 'tarjeta', 'transferencia', 'mixto'])->default('efectivo'),
        ];
    }
}

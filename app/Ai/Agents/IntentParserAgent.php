<?php

namespace App\Ai\Agents;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\Conversational;
use Laravel\Ai\Contracts\HasStructuredOutput;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Messages\Message;
use Laravel\Ai\Promptable;
use Stringable;

class IntentParserAgent implements Agent, Conversational, HasStructuredOutput
{
    use Promptable;

    /**
     * @param  array<int, Message>  $messages
     */
    public function __construct(
        private readonly array $messages = [],
    ) {}

    public function instructions(): Stringable|string
    {
        return <<<'PROMPT'
Eres el parser de intencion operativo de GodsLove.
Devuelve solo datos estructurados. No converses con el usuario.
Tu trabajo es entender si el mensaje pide registrar una venta, una consulta, una confirmacion, una cancelacion u otra cosa.
Para ventas, extrae items con producto_nombre tal como lo dijo el usuario, cantidad numerica y metodo de pago.
No inventes productos, cantidades ni pago. Si falta algo, marca missing_fields.
PROMPT;
    }

    /**
     * @return array<int, Message>
     */
    public function messages(): iterable
    {
        return $this->messages;
    }

    /**
     * @return array<int, Tool>
     */
    public function tools(): iterable
    {
        return [];
    }

    /**
     * @return array<string, mixed>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'intent' => $schema->string()
                ->enum(['registrar_venta', 'confirmar', 'cancelar', 'consulta', 'otra'])
                ->description('Intencion principal del usuario.')
                ->required(),
            'confidence' => $schema->number()
                ->description('Confianza entre 0 y 1.')
                ->required(),
            'items' => $schema->array()
                ->items($schema->object([
                    'producto_nombre' => $schema->string()->required(),
                    'cantidad' => $schema->number()->required(),
                    'selected_options' => $schema->object()
                        ->description('Opciones si el usuario las dio explicitamente.')
                        ->nullable(),
                ])->withoutAdditionalProperties())
                ->description('Items de venta si intent=registrar_venta.')
                ->required(),
            'metodo_pago' => $schema->string()
                ->enum(['efectivo', 'tarjeta', 'transferencia', 'mixto', 'desconocido'])
                ->required(),
            'missing_fields' => $schema->array()
                ->items($schema->string())
                ->description('Campos faltantes para ejecutar el flujo.')
                ->required(),
            'notes' => $schema->string()
                ->description('Notas breves para el backend.')
                ->nullable(),
        ];
    }
}

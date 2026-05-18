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
Eres el ROUTER DE PROCESOS operativo de GodsLove. Devuelve solo JSON estructurado; no converses con el usuario.

Tu tarea no es responder: es enrutar el siguiente paso de una maquina de estados conversacional.

Modelo mental:
- Cada conversacion puede estar dentro de un proceso activo: venta, caja, inventario, alta de insumo, alta de categoria, alta de producto, receta de producto, opciones/sabores, consulta u otro.
- Si el usuario responde con datos cortos, correcciones, "inventalo tu", "ese", "si", "no", "solo de prueba", etc., debes mantener el proceso activo inferido del historial, no empezar otro.
- Solo cambia de proceso si el usuario lo pide claramente.
- Un objetivo compuesto como crear producto + abrir caja + vender debe mantenerse como proceso activo aunque pase por categoria, producto, caja y venta. Enruta cada siguiente mensaje al flujo que desbloquea el siguiente paso.

Rutas disponibles:
- deterministic_sale: unica ruta para ventas que el backend preparara de forma determinista. Usala solo si el mensaje actual pide registrar/cobrar una venta o continua una venta activa.
- agent_tools: altas de categorias, insumos, productos, recetas, opciones, caja, inventario, consultas operativas o cualquier flujo que deba resolver el agente con tools.
- confirm: el usuario confirma una operacion pendiente.
- cancel: el usuario cancela o descarta una operacion pendiente.
- answer: charla breve o pregunta que no requiere tool.

Reglas duras:
- No conviertas altas de categorias, insumos, productos, recetas u opciones en ventas.
- Preguntas como "que se vendio", "que se ha vendido", "desglose de ventas" o "tickets recientes" son consultas operativas: route=agent_tools, active_flow=consulta.
- No conviertas una respuesta de seguimiento en venta salvo que el proceso activo sea venta.
- No inventes productos, cantidades, pago, precios, stock ni costos.
- Para ventas, extrae items con producto_nombre literal, cantidad numerica y metodo_pago. Si falta algo, marca missing_fields.
- "vendelo", "haz la venta", "cobralo" despues de crear un producto es venta: route=deterministic_sale, active_flow=venta, usando el producto mas reciente del historial si se entiende cual es.
- "abrela", "abre caja", o un numero aislado despues de pedir monto de caja es flujo de caja: route=agent_tools, active_flow=caja.
- Un precio/costo/tipo de producto enviado como seguimiento despues de pedir datos de producto es alta_producto: route=agent_tools, active_flow=alta_producto.
- Para "inventalo tu" dentro de alta_categoria u otro dato no financiero, route debe ser agent_tools, no deterministic_sale.
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
            'route' => $schema->string()
                ->enum(['deterministic_sale', 'agent_tools', 'confirm', 'cancel', 'answer'])
                ->description('Ruta de ejecucion que debe tomar el backend.')
                ->required(),
            'active_flow' => $schema->string()
                ->enum(['none', 'venta', 'caja', 'inventario', 'alta_insumo', 'alta_categoria', 'alta_producto', 'receta_producto', 'opciones_producto', 'consulta', 'otro'])
                ->description('Proceso conversacional activo despues de leer el historial.')
                ->required(),
            'flow_status' => $schema->string()
                ->enum(['new', 'continue', 'ready_to_prepare', 'waiting_user', 'ready_to_confirm', 'done'])
                ->description('Estado del proceso activo.')
                ->required(),
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
            'reason' => $schema->string()
                ->description('Razon breve de enrutamiento. No se muestra al usuario.')
                ->nullable(),
        ];
    }
}

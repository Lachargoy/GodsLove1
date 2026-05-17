<?php

namespace App\Ai\Agents;

use App\Ai\Tools\GodsLoveOperationsTool;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\Conversational;
use Laravel\Ai\Contracts\HasTools;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Messages\Message;
use Laravel\Ai\Promptable;
use Stringable;

class OperationsAgent implements Agent, Conversational, HasTools
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
Eres el asistente operativo interno de GodsLove.
Puedes consultar inventario, buscar productos, resumir caja, preparar ventas, preparar movimientos, dar de alta insumos, categorias, productos, recetas y opciones/sabores usando la tool operacion_godslove.
Nunca inventes productos, precios, stock, costos ni estado de caja.
Antes de modificar datos debes consultar o preparar la operacion, resumir productos, cantidades, total, caja e impacto en inventario, y pedir confirmacion al usuario.
Solo ejecuta acciones confirmar_* si el usuario confirmo claramente y tienes un confirmation_token vigente en el contexto.
Si faltan sabores, opciones, metodo de pago, monto contado, producto exacto, categoria, receta, unidad, costo o precio, pregunta algo concreto antes de preparar.
Mantén el contexto activo de la conversación: si estás dando de alta una categoria, insumo, producto, receta u opcion, interpreta respuestas cortas del usuario como datos para ese mismo flujo; no cambies a ventas salvo que el usuario hable claramente de vender, cobrar, pago, ticket o venta.
Si el usuario dice que inventes un nombre o descripcion de prueba para una categoria u otro dato no financiero, puedes proponer un valor simple de prueba y preparar la operacion para confirmacion. Nunca inventes precios, costos, stock, productos existentes ni impactos de inventario.
Cuando una tool devuelva requires_confirmation, responde con un resumen breve y menciona que se abrió la ventana de confirmación en la interfaz.
Responde en espanol claro, breve y accionable.
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
        return [
            app(GodsLoveOperationsTool::class),
        ];
    }
}

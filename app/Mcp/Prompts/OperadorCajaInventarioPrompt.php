<?php

namespace App\Mcp\Prompts;

use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Attributes\Title;
use Laravel\Mcp\Server\Prompt;
use Laravel\Mcp\Server\Prompts\Argument;

#[Name('operador-caja-inventario')]
#[Title('Operador caja inventario')]
#[Description('Prompt guia para operar ventas, caja e inventario usando las tools MCP documentadas, con consulta previa y confirmacion obligatoria.')]
class OperadorCajaInventarioPrompt extends Prompt
{
    public function handle(Request $request): Response
    {
        $modo = $request->get('modo', 'operacion');

        return Response::text(<<<PROMPT
Eres el asistente operativo de una heladeria en modo {$modo}.

Reglas obligatorias:
- Consulta primero con operations://manual y operations://catalog-summary cuando necesites orientarte.
- Nunca inventes productos, precios, stock, categorias ni sabores.
- Para ventas usa buscar_producto y estimar_venta antes de preparar_venta.
- Para caja usa resumen_caja antes de preparar_abrir_caja o preparar_cerrar_caja.
- Para inventario usa consultar_inventario antes de preparar_movimiento_inventario.
- Toda escritura requiere dos pasos: preparar_* devuelve confirmation_token; confirmar_* solo se llama si el usuario aprueba el resumen.
- Antes de confirmar resume productos, cantidades, total, metodo de pago, caja e impacto en inventario.
- Si faltan opciones configurables, pregunta exactamente que sabor/opcion quiere el usuario.
PROMPT);
    }

    /**
     * @return array<int, Argument>
     */
    public function arguments(): array
    {
        return [
            new Argument('modo', 'Contexto opcional: operacion, entrenamiento, auditoria o soporte.', false),
        ];
    }
}

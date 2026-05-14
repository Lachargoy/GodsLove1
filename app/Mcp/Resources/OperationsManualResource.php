<?php

namespace App\Mcp\Resources;

use App\Services\Mcp\OperationsAssistantService;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\MimeType;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Attributes\Title;
use Laravel\Mcp\Server\Attributes\Uri;
use Laravel\Mcp\Server\Resource;

#[Name('operations-manual')]
#[Title('Manual de operaciones')]
#[Uri('operations://manual')]
#[MimeType('application/json')]
#[Description('Manual corto para que la IA opere ventas, caja e inventario sin leer el codigo. Incluye flujos, reglas de confirmacion, prohibiciones y ejemplos.')]
class OperationsManualResource extends Resource
{
    public function handle(Request $request, OperationsAssistantService $operations): ResponseFactory
    {
        return Response::structured($operations->manual());
    }
}

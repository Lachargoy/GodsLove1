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

#[Name('operations-catalog-summary')]
#[Title('Resumen dinamico del catalogo')]
#[Uri('operations://catalog-summary')]
#[MimeType('application/json')]
#[Description('Resumen dinamico de categorias activas, total de productos activos, inventario bajo y estado de caja. Usalo como contexto inicial ligero.')]
class CatalogSummaryResource extends Resource
{
    public function handle(Request $request, OperationsAssistantService $operations): ResponseFactory
    {
        return Response::structured($operations->catalogSummary());
    }
}

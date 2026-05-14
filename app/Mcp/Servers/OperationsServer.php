<?php

namespace App\Mcp\Servers;

use App\Mcp\Prompts\OperadorCajaInventarioPrompt;
use App\Mcp\Resources\CatalogSummaryResource;
use App\Mcp\Resources\OperationsManualResource;
use App\Mcp\Tools\BuscarProductoTool;
use App\Mcp\Tools\ConfirmarAbrirCajaTool;
use App\Mcp\Tools\ConfirmarCerrarCajaTool;
use App\Mcp\Tools\ConfirmarMovimientoInventarioTool;
use App\Mcp\Tools\ConfirmarVentaTool;
use App\Mcp\Tools\ConsultarInventarioTool;
use App\Mcp\Tools\EstimarVentaTool;
use App\Mcp\Tools\PrepararAbrirCajaTool;
use App\Mcp\Tools\PrepararCerrarCajaTool;
use App\Mcp\Tools\PrepararMovimientoInventarioTool;
use App\Mcp\Tools\PrepararVentaTool;
use App\Mcp\Tools\ResumenCajaTool;
use Laravel\Mcp\Server;
use Laravel\Mcp\Server\Attributes\Instructions;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Attributes\Version;

#[Name('GodsLove Operations')]
#[Version('0.1.0')]
#[Instructions('Servidor MCP local para consultar inventario, preparar ventas, abrir/cerrar caja y registrar movimientos con confirmacion. Lee operations://manual antes de operar y nunca llames confirmar_* sin aprobacion explicita del usuario.')]
class OperationsServer extends Server
{
    /**
     * @var array<int, class-string>
     */
    protected array $tools = [
        ConsultarInventarioTool::class,
        BuscarProductoTool::class,
        ResumenCajaTool::class,
        EstimarVentaTool::class,
        PrepararVentaTool::class,
        ConfirmarVentaTool::class,
        PrepararAbrirCajaTool::class,
        ConfirmarAbrirCajaTool::class,
        PrepararCerrarCajaTool::class,
        ConfirmarCerrarCajaTool::class,
        PrepararMovimientoInventarioTool::class,
        ConfirmarMovimientoInventarioTool::class,
    ];

    /**
     * @var array<int, class-string>
     */
    protected array $resources = [
        OperationsManualResource::class,
        CatalogSummaryResource::class,
    ];

    /**
     * @var array<int, class-string>
     */
    protected array $prompts = [
        OperadorCajaInventarioPrompt::class,
    ];
}

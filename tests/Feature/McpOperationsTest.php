<?php

use App\Mcp\Prompts\OperadorCajaInventarioPrompt;
use App\Mcp\Resources\CatalogSummaryResource;
use App\Mcp\Resources\OperationsManualResource;
use App\Mcp\Servers\OperationsServer;
use App\Mcp\Tools\BuscarProductoTool;
use App\Mcp\Tools\ConfirmarAbrirCajaTool;
use App\Mcp\Tools\ConfirmarVentaTool;
use App\Mcp\Tools\ConsultarInventarioTool;
use App\Mcp\Tools\EstimarVentaTool;
use App\Mcp\Tools\PrepararAbrirCajaTool;
use App\Mcp\Tools\PrepararVentaTool;
use App\Models\CorteCaja;
use App\Models\InventoryItem;
use App\Models\MovimientoInventario;
use App\Models\Producto;
use App\Models\Unit;
use App\Models\User;
use App\Models\Venta;
use Database\Seeders\CategoriaGastoSeeder;
use Database\Seeders\CategoriaInsumoSeeder;
use Database\Seeders\CategoriaProductoSeeder;
use Database\Seeders\InsumoSeeder;
use Database\Seeders\InventoryCategorySeeder;
use Database\Seeders\ProductoInsumoSeeder;
use Database\Seeders\ProductoSeeder;
use Database\Seeders\UnitSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed([
        UnitSeeder::class,
        InventoryCategorySeeder::class,
        CategoriaProductoSeeder::class,
        CategoriaInsumoSeeder::class,
        CategoriaGastoSeeder::class,
        ProductoSeeder::class,
        InsumoSeeder::class,
        ProductoInsumoSeeder::class,
    ]);
});

test('mcp expone documentacion y contratos basicos', function () {
    $inventoryTool = new ConsultarInventarioTool;
    $productTool = new BuscarProductoTool;

    expect($inventoryTool->toArray())
        ->toHaveKey('inputSchema')
        ->and($inventoryTool->toArray()['annotations'])
        ->toHaveKey((new IsReadOnly)->key())
        ->and($productTool->description())
        ->toContain('No modifica datos');

    OperationsServer::resource(OperationsManualResource::class)
        ->assertOk()
        ->assertSee('Manual MCP de operaciones')
        ->assertSee('confirmation_token');

    OperationsServer::resource(CatalogSummaryResource::class)
        ->assertOk()
        ->assertSee('productos_activos');

    OperationsServer::prompt(OperadorCajaInventarioPrompt::class)
        ->assertOk()
        ->assertSee('Nunca inventes productos');
});

test('consulta inventario y busca productos desde mcp', function () {
    OperationsServer::tool(ConsultarInventarioTool::class, [
        'only_low' => true,
    ])
        ->assertOk()
        ->assertSee('items');

    OperationsServer::tool(BuscarProductoTool::class, [
        'search' => 'Cono',
    ])
        ->assertOk()
        ->assertSee('Cono sencillo')
        ->assertSee('precio_venta');
});

test('estima venta sin crear registros', function () {
    $product = Producto::query()->where('nombre', 'Cono sencillo')->firstOrFail();

    OperationsServer::tool(EstimarVentaTool::class, [
        'items' => [
            ['producto_id' => $product->id, 'cantidad' => 2],
        ],
        'metodo_pago' => 'efectivo',
    ])
        ->assertOk()
        ->assertSee('puede_confirmarse')
        ->assertSee('total');

    expect(Venta::query()->count())->toBe(0)
        ->and(MovimientoInventario::query()->count())->toBe(0);
});

test('preparar venta bloquea si no hay caja abierta', function () {
    $product = Producto::query()->where('nombre', 'Cono sencillo')->firstOrFail();

    OperationsServer::tool(PrepararVentaTool::class, [
        'items' => [
            ['producto_id' => $product->id, 'cantidad' => 1],
        ],
    ])
        ->assertOk()
        ->assertSee('blocked')
        ->assertSee('No hay una caja');

    expect(Venta::query()->count())->toBe(0);
});

test('preparar y confirmar venta crea venta y consume token una sola vez', function () {
    $user = User::factory()->create();
    $product = Producto::query()->where('nombre', 'Cono sencillo')->firstOrFail();

    CorteCaja::query()->create([
        'user_id' => $user->id,
        'fecha_apertura' => now(),
        'monto_inicial' => 500,
        'estado' => 'abierto',
    ]);

    $response = OperationsServer::actingAs($user)->tool(PrepararVentaTool::class, [
        'items' => [
            ['producto_id' => $product->id, 'cantidad' => 2],
        ],
        'metodo_pago' => 'efectivo',
    ]);

    $response
        ->assertOk()
        ->assertSee('requires_confirmation');

    $payload = responsePayload($response);
    $token = $payload['confirmation_token'];

    OperationsServer::actingAs($user)->tool(ConfirmarVentaTool::class, [
        'confirmation_token' => $token,
    ])
        ->assertOk()
        ->assertSee('confirmed')
        ->assertSee('venta');

    OperationsServer::actingAs($user)->tool(ConfirmarVentaTool::class, [
        'confirmation_token' => $token,
    ])
        ->assertOk()
        ->assertSee('Token de confirmacion invalido');

    expect(Venta::query()->count())->toBe(1)
        ->and(MovimientoInventario::query()->where('tipo', 'venta')->count())->toBeGreaterThan(0);
});

test('abrir caja requiere preparar y confirmar', function () {
    $user = User::factory()->create();

    $prepared = OperationsServer::actingAs($user)->tool(PrepararAbrirCajaTool::class, [
        'monto_inicial' => 300,
    ]);

    expect(CorteCaja::query()->count())->toBe(0);

    $token = responsePayload($prepared)['confirmation_token'];

    OperationsServer::actingAs($user)->tool(ConfirmarAbrirCajaTool::class, [
        'confirmation_token' => $token,
    ])
        ->assertOk()
        ->assertSee('confirmed');

    expect(CorteCaja::query()->count())->toBe(1)
        ->and((float) CorteCaja::query()->firstOrFail()->monto_inicial)->toBe(300.0);
});

test('confirmation token queda amarrado al operador', function () {
    $firstUser = User::factory()->create();
    $secondUser = User::factory()->create();

    $prepared = OperationsServer::actingAs($firstUser)->tool(PrepararAbrirCajaTool::class, [
        'monto_inicial' => 300,
    ]);

    $token = responsePayload($prepared)['confirmation_token'];

    OperationsServer::actingAs($secondUser)->tool(ConfirmarAbrirCajaTool::class, [
        'confirmation_token' => $token,
    ])
        ->assertOk()
        ->assertSee('no corresponde al operador actual');

    expect(CorteCaja::query()->count())->toBe(0);

    OperationsServer::actingAs($firstUser)->tool(ConfirmarAbrirCajaTool::class, [
        'confirmation_token' => $token,
    ])
        ->assertOk()
        ->assertSee('confirmed');

    expect(CorteCaja::query()->count())->toBe(1);
});

test('preparar venta configurable sin opciones devuelve error accionable', function () {
    $unit = Unit::query()->firstOrFail();
    $inventoryItem = InventoryItem::query()->create([
        'unit_id' => $unit->id,
        'name' => 'Sabor vainilla',
        'current_stock' => 10,
        'minimum_stock' => 1,
        'average_cost' => 5,
        'allows_decimals' => true,
        'is_consumable' => true,
        'is_active' => true,
    ]);

    $product = Producto::query()->create([
        'nombre' => 'Helado doble MCP',
        'precio_venta' => 45,
        'costo_estimado' => 10,
        'product_type' => 'configurable',
        'activo' => true,
    ]);

    $group = $product->productOptionGroups()->create([
        'name' => 'Sabores',
        'required_quantity' => 2,
        'min_quantity' => 2,
        'max_quantity' => 2,
    ]);

    $group->optionItems()->create([
        'inventory_item_id' => $inventoryItem->id,
        'quantity_per_selection' => 1,
        'is_active' => true,
    ]);

    OperationsServer::tool(EstimarVentaTool::class, [
        'items' => [
            ['producto_id' => $product->id, 'cantidad' => 1],
        ],
    ])
        ->assertOk()
        ->assertSee('requiere al menos');
});

function responsePayload($testResponse): array
{
    $property = new ReflectionProperty($testResponse, 'response');
    $property->setAccessible(true);
    $response = $property->getValue($testResponse)->toArray();

    return $response['result']['structuredContent'];
}

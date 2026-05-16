<?php

use App\Mcp\Prompts\OperadorCajaInventarioPrompt;
use App\Mcp\Resources\CatalogSummaryResource;
use App\Mcp\Resources\OperationsManualResource;
use App\Mcp\Servers\OperationsServer;
use App\Mcp\Tools\BuscarProductoTool;
use App\Mcp\Tools\ConfirmarAbrirCajaTool;
use App\Mcp\Tools\ConfirmarAltaCategoriaTool;
use App\Mcp\Tools\ConfirmarAltaInsumoTool;
use App\Mcp\Tools\ConfirmarAltaProductoTool;
use App\Mcp\Tools\ConfirmarOpcionesProductoTool;
use App\Mcp\Tools\ConfirmarRecetaProductoTool;
use App\Mcp\Tools\ConfirmarVentaTool;
use App\Mcp\Tools\ConsultarInventarioTool;
use App\Mcp\Tools\EstimarVentaTool;
use App\Mcp\Tools\PrepararAbrirCajaTool;
use App\Mcp\Tools\PrepararAltaCategoriaTool;
use App\Mcp\Tools\PrepararAltaInsumoTool;
use App\Mcp\Tools\PrepararAltaProductoTool;
use App\Mcp\Tools\PrepararOpcionesProductoTool;
use App\Mcp\Tools\PrepararRecetaProductoTool;
use App\Mcp\Tools\PrepararVentaTool;
use App\Models\CategoriaInsumo;
use App\Models\CategoriaProducto;
use App\Models\CorteCaja;
use App\Models\Insumo;
use App\Models\InventoryItem;
use App\Models\MovimientoInventario;
use App\Models\Producto;
use App\Models\ProductoInsumo;
use App\Models\ProductOptionGroup;
use App\Models\ProductOptionItem;
use App\Models\ProductRecipe;
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

test('preparar y confirmar alta de insumo crea insumo e item de inventario', function () {
    $user = User::factory()->create();
    $category = CategoriaInsumo::query()->where('activo', true)->firstOrFail();

    $prepared = OperationsServer::actingAs($user)->tool(PrepararAltaInsumoTool::class, [
        'nombre' => 'Azucar refinada MCP',
        'categoria_insumo_id' => $category->id,
        'unidad_medida' => 'kg',
        'cantidad_actual' => 10,
        'cantidad_minima' => 2,
        'costo_unitario' => 24.50,
    ]);

    $prepared
        ->assertOk()
        ->assertSee('requires_confirmation')
        ->assertSee('Azucar refinada MCP');

    expect(Insumo::query()->where('nombre', 'Azucar refinada MCP')->exists())->toBeFalse()
        ->and(InventoryItem::query()->where('name', 'Azucar refinada MCP')->exists())->toBeFalse();

    $token = responsePayload($prepared)['confirmation_token'];

    OperationsServer::actingAs($user)->tool(ConfirmarAltaInsumoTool::class, [
        'confirmation_token' => $token,
    ])
        ->assertOk()
        ->assertSee('confirmed')
        ->assertSee('alta_insumo');

    $insumo = Insumo::query()
        ->with('inventoryItem.unit')
        ->where('nombre', 'Azucar refinada MCP')
        ->firstOrFail();

    expect($insumo->inventoryItem)->not->toBeNull()
        ->and($insumo->inventoryItem->name)->toBe('Azucar refinada MCP')
        ->and($insumo->inventoryItem->unit?->abbreviation)->toBe('kg')
        ->and((float) $insumo->inventoryItem->current_stock)->toBe(10.0)
        ->and((float) $insumo->inventoryItem->average_cost)->toBe(24.5);
});

test('preparar y confirmar alta de categoria y producto crea registros de catalogo', function () {
    $user = User::factory()->create();
    $unit = Unit::query()->firstOrFail();
    $inventoryItem = InventoryItem::query()->create([
        'unit_id' => $unit->id,
        'name' => 'Paleta simple inventario MCP',
        'current_stock' => 20,
        'minimum_stock' => 2,
        'average_cost' => 12,
        'allows_decimals' => false,
        'is_consumable' => true,
        'is_active' => true,
    ]);

    $preparedCategory = OperationsServer::actingAs($user)->tool(PrepararAltaCategoriaTool::class, [
        'tipo' => 'producto',
        'nombre' => 'Paletas MCP',
        'descripcion' => 'Categoria creada por asistente',
    ]);

    $preparedCategory
        ->assertOk()
        ->assertSee('requires_confirmation');

    expect(CategoriaProducto::query()->where('nombre', 'Paletas MCP')->exists())->toBeFalse();

    OperationsServer::actingAs($user)->tool(ConfirmarAltaCategoriaTool::class, [
        'confirmation_token' => responsePayload($preparedCategory)['confirmation_token'],
    ])
        ->assertOk()
        ->assertSee('confirmed');

    $category = CategoriaProducto::query()->where('nombre', 'Paletas MCP')->firstOrFail();

    $preparedProduct = OperationsServer::actingAs($user)->tool(PrepararAltaProductoTool::class, [
        'nombre' => 'Paleta simple MCP',
        'categoria_producto_id' => $category->id,
        'precio_venta' => 35,
        'product_type' => 'simple',
        'inventory_item_id' => $inventoryItem->id,
    ]);

    $preparedProduct
        ->assertOk()
        ->assertSee('requires_confirmation')
        ->assertSee('Paleta simple MCP');

    expect(Producto::query()->where('nombre', 'Paleta simple MCP')->exists())->toBeFalse();

    OperationsServer::actingAs($user)->tool(ConfirmarAltaProductoTool::class, [
        'confirmation_token' => responsePayload($preparedProduct)['confirmation_token'],
    ])
        ->assertOk()
        ->assertSee('confirmed')
        ->assertSee('alta_producto');

    $product = Producto::query()->where('nombre', 'Paleta simple MCP')->firstOrFail();

    expect($product->categoria_producto_id)->toBe($category->id)
        ->and((float) $product->precio_venta)->toBe(35.0)
        ->and($product->product_type)->toBe('simple')
        ->and($product->inventory_item_id)->toBe($inventoryItem->id)
        ->and($inventoryItem->fresh()->is_sellable)->toBeTrue();
});

test('preparar y confirmar receta y opciones configura producto', function () {
    $user = User::factory()->create();
    $unit = Unit::query()->firstOrFail();
    $categoriaInsumo = CategoriaInsumo::query()->where('activo', true)->firstOrFail();
    $product = Producto::query()->where('activo', true)->firstOrFail();
    $inventoryItem = InventoryItem::query()->create([
        'unit_id' => $unit->id,
        'name' => 'Base receta MCP',
        'current_stock' => 20,
        'minimum_stock' => 2,
        'average_cost' => 8,
        'allows_decimals' => true,
        'is_consumable' => true,
        'is_active' => true,
    ]);
    $insumo = Insumo::query()->create([
        'categoria_insumo_id' => $categoriaInsumo->id,
        'inventory_item_id' => $inventoryItem->id,
        'nombre' => 'Base receta MCP',
        'unidad_medida' => $unit->name,
        'cantidad_actual' => 20,
        'cantidad_minima' => 2,
        'costo_unitario' => 8,
        'activo' => true,
    ]);

    $preparedRecipe = OperationsServer::actingAs($user)->tool(PrepararRecetaProductoTool::class, [
        'producto_id' => $product->id,
        'items' => [
            [
                'insumo_id' => $insumo->id,
                'cantidad_requerida' => 0.25,
            ],
        ],
    ]);

    $preparedRecipe
        ->assertOk()
        ->assertSee('requires_confirmation')
        ->assertSee('receta_producto');

    OperationsServer::actingAs($user)->tool(ConfirmarRecetaProductoTool::class, [
        'confirmation_token' => responsePayload($preparedRecipe)['confirmation_token'],
    ])
        ->assertOk()
        ->assertSee('confirmed');

    expect(ProductoInsumo::query()
        ->where('producto_id', $product->id)
        ->where('insumo_id', $insumo->id)
        ->exists())->toBeTrue()
        ->and(ProductRecipe::query()
            ->where('product_id', $product->id)
            ->where('inventory_item_id', $insumo->inventory_item_id)
            ->exists())->toBeTrue();

    $preparedOptions = OperationsServer::actingAs($user)->tool(PrepararOpcionesProductoTool::class, [
        'producto_id' => $product->id,
        'group_name' => 'Sabores MCP',
        'required_quantity' => 2,
        'min_quantity' => 1,
        'max_quantity' => 2,
        'options' => [
            [
                'inventory_item_id' => $inventoryItem->id,
                'quantity_per_selection' => 0.120,
                'extra_price' => 0,
            ],
        ],
    ]);

    $preparedOptions
        ->assertOk()
        ->assertSee('requires_confirmation')
        ->assertSee('Sabores MCP');

    OperationsServer::actingAs($user)->tool(ConfirmarOpcionesProductoTool::class, [
        'confirmation_token' => responsePayload($preparedOptions)['confirmation_token'],
    ])
        ->assertOk()
        ->assertSee('confirmed')
        ->assertSee('opciones_producto');

    $group = ProductOptionGroup::query()
        ->where('product_id', $product->id)
        ->where('name', 'Sabores MCP')
        ->firstOrFail();

    expect((float) $group->required_quantity)->toBe(2.0)
        ->and($product->fresh()->product_type)->toBe('configurable')
        ->and(ProductOptionItem::query()
            ->where('product_option_group_id', $group->id)
            ->where('inventory_item_id', $inventoryItem->id)
            ->exists())->toBeTrue();
});

function responsePayload($testResponse): array
{
    $property = new ReflectionProperty($testResponse, 'response');
    $property->setAccessible(true);
    $response = $property->getValue($testResponse)->toArray();

    return $response['result']['structuredContent'];
}

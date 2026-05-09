<?php

use App\Models\Insumo;
use App\Models\CategoriaProducto;
use App\Models\CorteCaja;
use App\Models\InventoryItem;
use App\Models\InventoryMovement;
use App\Models\MovimientoInventario;
use App\Models\Producto;
use App\Models\ProductOptionGroup;
use App\Models\ProductOptionItem;
use App\Models\SaleDetailComponent;
use App\Models\Unit;
use App\Models\User;
use App\Models\Venta;
use App\Models\VentaDetalle;
use Database\Seeders\CategoriaGastoSeeder;
use Database\Seeders\CategoriaInsumoSeeder;
use Database\Seeders\CategoriaProductoSeeder;
use Database\Seeders\InsumoSeeder;
use Database\Seeders\ProductoInsumoSeeder;
use Database\Seeders\ProductoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed([
        CategoriaProductoSeeder::class,
        CategoriaInsumoSeeder::class,
        CategoriaGastoSeeder::class,
        ProductoSeeder::class,
        InsumoSeeder::class,
        ProductoInsumoSeeder::class,
    ]);
});

test('usuario autenticado puede ver el punto de venta', function () {
    $user = User::factory()->create();

    $response = $this->actingAs($user)->get(route('ventas.punto'));

    $response->assertOk();
    $response->assertSee('Total vendido hoy');
    $response->assertSee('Productos');
    $response->assertSee('Carrito');
    $response->assertSee('Últimas ventas');
    $response->assertSee('Top productos hoy');
    $response->assertSee('Insumos consumidos');
    $response->assertSee('Cono sencillo');
    $response->assertSee('Cono doble');
    $response->assertSee('Malteada');
});

test('puede agregar producto al carrito', function () {
    $producto = Producto::query()->where('nombre', 'Cono sencillo')->firstOrFail();

    Livewire::test('ventas.punto-venta')
        ->call('agregarProducto', $producto->id)
        ->assertSet("carrito.{$producto->id}.cantidad", 1)
        ->assertSee('Cono sencillo');
});

test('puede filtrar menu de venta por categoria', function () {
    $categoria = CategoriaProducto::query()->where('nombre', 'Malteadas')->firstOrFail();

    Livewire::test('ventas.punto-venta')
        ->set('categoria_producto_id', (string) $categoria->id)
        ->assertSee('Malteada')
        ->assertDontSee('Cono sencillo');
});

test('calcula cambio y falta por cobrar en el carrito', function () {
    $producto = Producto::query()->where('nombre', 'Cono sencillo')->firstOrFail();

    Livewire::test('ventas.punto-venta')
        ->call('agregarProducto', $producto->id)
        ->call('actualizarCantidad', $producto->id, 2)
        ->set('monto_recibido', '100')
        ->assertSet('monto_recibido', '100')
        ->assertSee('Cambio')
        ->assertSee('$40.00');
});

test('puede confirmar venta de dos conos sencillos', function () {
    $user = User::factory()->create();
    $producto = Producto::query()->where('nombre', 'Cono sencillo')->firstOrFail();
    $corte = CorteCaja::query()->create([
        'user_id' => $user->id,
        'fecha_apertura' => now(),
        'monto_inicial' => 100,
        'estado' => 'abierto',
    ]);

    Livewire::actingAs($user)
        ->test('ventas.punto-venta')
        ->call('agregarProducto', $producto->id)
        ->call('actualizarCantidad', $producto->id, 2)
        ->set('monto_recibido', '100')
        ->call('confirmarVenta')
        ->assertSee('Venta V-000001 registrada correctamente por $60.00.')
        ->assertSee('V-000001')
        ->assertSee('$60.00')
        ->assertSee('$23.80')
        ->assertSee('$36.20')
        ->assertSee('Cono sencillo')
        ->assertSee('Conos')
        ->assertSee('Helado de vainilla')
        ->assertSee('Servilletas')
        ->assertSet('carrito', [])
        ->assertSet('descuento', '0')
        ->assertSet('monto_recibido', '')
        ->assertSet('metodo_pago', 'efectivo');

    $venta = Venta::query()->with('detalles')->firstOrFail();
    $conos = Insumo::query()->where('nombre', 'Conos')->firstOrFail();
    $helado = Insumo::query()->where('nombre', 'Helado de vainilla')->firstOrFail();
    $servilletas = Insumo::query()->where('nombre', 'Servilletas')->firstOrFail();

    expect(Venta::query()->count())->toBe(1)
        ->and(VentaDetalle::query()->count())->toBe(1)
        ->and($venta->corte_caja_id)->toBe($corte->id)
        ->and((float) $venta->total)->toBe(60.0)
        ->and((float) $conos->cantidad_actual)->toBe(98.0)
        ->and((float) $helado->cantidad_actual)->toBe(9.76)
        ->and((float) $servilletas->cantidad_actual)->toBe(198.0)
        ->and(MovimientoInventario::query()->where('tipo', 'venta')->count())->toBe(3)
        ->and((float) MovimientoInventario::query()->where('tipo', 'venta')->sum(\Illuminate\Support\Facades\DB::raw('ABS(cantidad) * costo_unitario')))->toBeGreaterThan(0);
});

test('muestra error y no crea venta si la cantidad es imposible', function () {
    $user = User::factory()->create();
    $producto = Producto::query()->where('nombre', 'Cono doble')->firstOrFail();
    CorteCaja::query()->create([
        'user_id' => $user->id,
        'fecha_apertura' => now(),
        'monto_inicial' => 100,
        'estado' => 'abierto',
    ]);

    Livewire::actingAs($user)
        ->test('ventas.punto-venta')
        ->call('agregarProducto', $producto->id)
        ->call('actualizarCantidad', $producto->id, 1000)
        ->call('confirmarVenta')
        ->assertSee('Inventario insuficiente para el insumo: Conos');

    expect(Venta::query()->count())->toBe(0)
        ->and(VentaDetalle::query()->count())->toBe(0)
        ->and(MovimientoInventario::query()->where('tipo', 'venta')->count())->toBe(0);
});

test('no permite confirmar venta con carrito vacio', function () {
    $user = User::factory()->create();

    Livewire::actingAs($user)
        ->test('ventas.punto-venta')
        ->call('confirmarVenta')
        ->assertHasErrors(['carrito']);

    expect(Venta::query()->count())->toBe(0);
});

test('puede configurar y vender una nieve doble con sabores desde el punto de venta', function () {
    $user = User::factory()->create();
    CorteCaja::query()->create([
        'user_id' => $user->id,
        'fecha_apertura' => now(),
        'monto_inicial' => 100,
        'estado' => 'abierto',
    ]);
    $unit = Unit::query()->create([
        'name' => 'bola',
        'abbreviation' => 'bola',
        'allows_decimals' => false,
    ]);

    $fresa = InventoryItem::query()->create([
        'unit_id' => $unit->id,
        'name' => 'Nieve fresa',
        'current_stock' => 10,
        'average_cost' => 6,
        'allows_decimals' => false,
        'is_consumable' => true,
        'is_active' => true,
    ]);

    $vainilla = InventoryItem::query()->create([
        'unit_id' => $unit->id,
        'name' => 'Nieve vainilla',
        'current_stock' => 10,
        'average_cost' => 5,
        'allows_decimals' => false,
        'is_consumable' => true,
        'is_active' => true,
    ]);

    $producto = Producto::query()->create([
        'nombre' => 'Nieve doble',
        'product_type' => 'configurable',
        'precio_venta' => 45,
        'costo_estimado' => 11,
        'activo' => true,
    ]);

    $grupo = ProductOptionGroup::query()->create([
        'product_id' => $producto->id,
        'name' => 'Sabores',
        'required_quantity' => 2,
        'min_quantity' => 2,
        'max_quantity' => 2,
    ]);

    $opcionFresa = ProductOptionItem::query()->create([
        'product_option_group_id' => $grupo->id,
        'inventory_item_id' => $fresa->id,
        'quantity_per_selection' => 1,
        'is_active' => true,
    ]);

    $opcionVainilla = ProductOptionItem::query()->create([
        'product_option_group_id' => $grupo->id,
        'inventory_item_id' => $vainilla->id,
        'quantity_per_selection' => 1,
        'is_active' => true,
    ]);

    Livewire::actingAs($user)
        ->test('ventas.punto-venta')
        ->call('agregarProducto', $producto->id)
        ->assertSet('configuring_product_id', (string) $producto->id)
        ->assertSee('Configurar Nieve doble')
        ->call('incrementarOpcionConfigurable', $grupo->id, $opcionFresa->id)
        ->call('incrementarOpcionConfigurable', $grupo->id, $opcionVainilla->id)
        ->call('agregarProductoConfigurado')
        ->assertSet('configuring_product_id', '')
        ->assertSee('Nieve fresa x 1')
        ->assertSee('Nieve vainilla x 1')
        ->set('monto_recibido', '50')
        ->call('confirmarVenta')
        ->assertSee('Venta V-000001 registrada correctamente por $45.00.');

    expect((float) $fresa->fresh()->current_stock)->toBe(9.0)
        ->and((float) $vainilla->fresh()->current_stock)->toBe(9.0)
        ->and(Venta::query()->count())->toBe(1)
        ->and(VentaDetalle::query()->count())->toBe(1)
        ->and(InventoryMovement::query()->where('movement_type', 'sale')->count())->toBe(2)
        ->and(SaleDetailComponent::query()->count())->toBe(2);
});

test('configurable descuenta sabores y presentacion como cono o vaso unicel', function () {
    $user = User::factory()->create();
    CorteCaja::query()->create([
        'user_id' => $user->id,
        'fecha_apertura' => now(),
        'monto_inicial' => 100,
        'estado' => 'abierto',
    ]);

    $litro = Unit::query()->create([
        'name' => 'litro',
        'abbreviation' => 'L',
        'allows_decimals' => true,
    ]);

    $pieza = Unit::query()->create([
        'name' => 'pieza',
        'abbreviation' => 'pz',
        'allows_decimals' => false,
    ]);

    $fresa = InventoryItem::query()->create([
        'unit_id' => $litro->id,
        'name' => 'Nieve fresa',
        'current_stock' => 10,
        'average_cost' => 80,
        'allows_decimals' => true,
        'is_consumable' => true,
        'is_active' => true,
    ]);

    $nuez = InventoryItem::query()->create([
        'unit_id' => $litro->id,
        'name' => 'Nieve nuez',
        'current_stock' => 10,
        'average_cost' => 95,
        'allows_decimals' => true,
        'is_consumable' => true,
        'is_active' => true,
    ]);

    $cono = InventoryItem::query()->create([
        'unit_id' => $pieza->id,
        'name' => 'Cono',
        'current_stock' => 50,
        'average_cost' => 1.5,
        'allows_decimals' => false,
        'is_consumable' => true,
        'is_active' => true,
    ]);

    $vasoUnicel = InventoryItem::query()->create([
        'unit_id' => $pieza->id,
        'name' => 'Vaso unicel',
        'current_stock' => 50,
        'average_cost' => 1.25,
        'allows_decimals' => false,
        'is_consumable' => true,
        'is_active' => true,
    ]);

    $producto = Producto::query()->create([
        'nombre' => 'Nieve doble',
        'product_type' => 'configurable',
        'precio_venta' => 45,
        'costo_estimado' => 20,
        'activo' => true,
    ]);

    $sabores = ProductOptionGroup::query()->create([
        'product_id' => $producto->id,
        'name' => 'Sabores',
        'required_quantity' => 2,
        'min_quantity' => 2,
        'max_quantity' => 2,
    ]);

    $presentacion = ProductOptionGroup::query()->create([
        'product_id' => $producto->id,
        'name' => 'Presentacion',
        'required_quantity' => 1,
        'min_quantity' => 1,
        'max_quantity' => 1,
    ]);

    $opcionFresa = ProductOptionItem::query()->create([
        'product_option_group_id' => $sabores->id,
        'inventory_item_id' => $fresa->id,
        'quantity_per_selection' => 0.095,
        'is_active' => true,
    ]);

    $opcionNuez = ProductOptionItem::query()->create([
        'product_option_group_id' => $sabores->id,
        'inventory_item_id' => $nuez->id,
        'quantity_per_selection' => 0.095,
        'is_active' => true,
    ]);

    ProductOptionItem::query()->create([
        'product_option_group_id' => $presentacion->id,
        'inventory_item_id' => $cono->id,
        'quantity_per_selection' => 1,
        'is_active' => true,
    ]);

    $opcionVasoUnicel = ProductOptionItem::query()->create([
        'product_option_group_id' => $presentacion->id,
        'inventory_item_id' => $vasoUnicel->id,
        'quantity_per_selection' => 1,
        'is_active' => true,
    ]);

    Livewire::actingAs($user)
        ->test('ventas.punto-venta')
        ->call('agregarProducto', $producto->id)
        ->call('incrementarOpcionConfigurable', $sabores->id, $opcionFresa->id)
        ->call('incrementarOpcionConfigurable', $sabores->id, $opcionNuez->id)
        ->call('incrementarOpcionConfigurable', $presentacion->id, $opcionVasoUnicel->id)
        ->call('agregarProductoConfigurado')
        ->assertHasNoErrors()
        ->assertSee('Nieve fresa x 1')
        ->assertSee('Nieve nuez x 1')
        ->assertSee('Vaso unicel x 1')
        ->set('monto_recibido', '50')
        ->call('confirmarVenta')
        ->assertSee('Venta V-000001 registrada correctamente por $45.00.');

    expect((float) $fresa->fresh()->current_stock)->toBe(9.905)
        ->and((float) $nuez->fresh()->current_stock)->toBe(9.905)
        ->and((float) $vasoUnicel->fresh()->current_stock)->toBe(49.0)
        ->and((float) $cono->fresh()->current_stock)->toBe(50.0)
        ->and(InventoryMovement::query()->where('movement_type', 'sale')->count())->toBe(3)
        ->and(SaleDetailComponent::query()->count())->toBe(3);
});

test('configurable con un sabor requerido no permite exceder el maximo del grupo', function () {
    $unit = Unit::query()->create([
        'name' => 'litro',
        'abbreviation' => 'L',
        'allows_decimals' => true,
    ]);

    $dulceLeche = InventoryItem::query()->create([
        'unit_id' => $unit->id,
        'name' => 'Helado dulce de leche',
        'current_stock' => 5,
        'average_cost' => 90,
        'allows_decimals' => true,
        'is_consumable' => true,
        'is_active' => true,
    ]);

    $producto = Producto::query()->create([
        'nombre' => 'Helado sencillo',
        'product_type' => 'configurable',
        'precio_venta' => 35,
        'costo_estimado' => 10.8,
        'activo' => true,
    ]);

    $grupo = ProductOptionGroup::query()->create([
        'product_id' => $producto->id,
        'name' => 'Sabores',
        'required_quantity' => 1,
        'min_quantity' => 1,
        'max_quantity' => 1,
    ]);

    $opcionDulceLeche = ProductOptionItem::query()->create([
        'product_option_group_id' => $grupo->id,
        'inventory_item_id' => $dulceLeche->id,
        'quantity_per_selection' => 0.120,
        'is_active' => true,
    ]);

    Livewire::test('ventas.punto-venta')
        ->call('agregarProducto', $producto->id)
        ->call('incrementarOpcionConfigurable', $grupo->id, $opcionDulceLeche->id)
        ->call('incrementarOpcionConfigurable', $grupo->id, $opcionDulceLeche->id)
        ->call('incrementarOpcionConfigurable', $grupo->id, $opcionDulceLeche->id)
        ->assertSet("selected_options.{$grupo->id}.{$opcionDulceLeche->id}", 1)
        ->call('agregarProductoConfigurado')
        ->assertHasNoErrors()
        ->assertSet('configuring_product_id', '')
        ->assertSee('Helado dulce de leche x 1');
});

test('configurable permite repetir el mismo sabor hasta el maximo del grupo', function () {
    $unit = Unit::query()->create([
        'name' => 'litro',
        'abbreviation' => 'L',
        'allows_decimals' => true,
    ]);

    $dulceLeche = InventoryItem::query()->create([
        'unit_id' => $unit->id,
        'name' => 'Helado dulce de leche',
        'current_stock' => 5,
        'average_cost' => 90,
        'allows_decimals' => true,
        'is_consumable' => true,
        'is_active' => true,
    ]);

    $producto = Producto::query()->create([
        'nombre' => 'Helado triple',
        'product_type' => 'configurable',
        'precio_venta' => 55,
        'costo_estimado' => 32.4,
        'activo' => true,
    ]);

    $grupo = ProductOptionGroup::query()->create([
        'product_id' => $producto->id,
        'name' => 'Sabores',
        'required_quantity' => 3,
        'min_quantity' => 3,
        'max_quantity' => 3,
    ]);

    $opcionDulceLeche = ProductOptionItem::query()->create([
        'product_option_group_id' => $grupo->id,
        'inventory_item_id' => $dulceLeche->id,
        'quantity_per_selection' => 0.120,
        'is_active' => true,
    ]);

    Livewire::test('ventas.punto-venta')
        ->call('agregarProducto', $producto->id)
        ->call('incrementarOpcionConfigurable', $grupo->id, $opcionDulceLeche->id)
        ->call('incrementarOpcionConfigurable', $grupo->id, $opcionDulceLeche->id)
        ->call('incrementarOpcionConfigurable', $grupo->id, $opcionDulceLeche->id)
        ->assertSet("selected_options.{$grupo->id}.{$opcionDulceLeche->id}", 3)
        ->call('agregarProductoConfigurado')
        ->assertHasNoErrors()
        ->assertSet('configuring_product_id', '')
        ->assertSee('Helado dulce de leche x 3');
});

test('muestra un mensaje claro si un configurable no tiene sabores cargados', function () {
    $producto = Producto::query()->create([
        'nombre' => 'Helado doble',
        'product_type' => 'configurable',
        'precio_venta' => 49,
        'costo_estimado' => 0,
        'activo' => true,
    ]);

    ProductOptionGroup::query()->create([
        'product_id' => $producto->id,
        'name' => 'Sabores',
        'required_quantity' => 2,
        'min_quantity' => 2,
        'max_quantity' => 2,
    ]);

    Livewire::test('ventas.punto-venta')
        ->call('agregarProducto', $producto->id)
        ->assertSet('configuring_product_id', '')
        ->assertSee('Este producto configurable no tiene sabores u opciones cargadas todavia. Entra a Editar receta y agrega opciones al grupo Sabores.');
});

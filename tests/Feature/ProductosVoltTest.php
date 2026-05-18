<?php

use App\Models\CategoriaProducto;
use App\Models\Insumo;
use App\Models\InventoryItem;
use App\Models\Producto;
use App\Models\ProductoInsumo;
use App\Models\ProductOptionGroup;
use App\Models\Unit;
use App\Models\User;
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

test('usuario autenticado puede ver productos', function () {
    $user = User::factory()->create();

    $response = $this->actingAs($user)->get(route('productos.index'));

    $response->assertOk();
    $response->assertSee('Productos');
    $response->assertSee('Cono sencillo');
    $response->assertSee('Cono doble');
    $response->assertSee('Malteada');
    $response->assertSee('Editar receta');
    $response->assertSee('Administrar categorias');
});

test('puede crear un producto nuevo desde volt', function () {
    $categoria = CategoriaProducto::query()->where('nombre', 'Toppings')->firstOrFail();
    $conos = Insumo::query()->where('nombre', 'Conos')->firstOrFail();
    $chocolate = Insumo::query()->where('nombre', 'Chocolate líquido')->firstOrFail();

    Livewire::test('productos.index')
        ->set('categoria_producto_id', (string) $categoria->id)
        ->set('nombre', 'Banana split')
        ->set('descripcion', 'Postre helado con fruta')
        ->set('precio_venta', '85')
        ->set('costo_estimado', '0')
        ->set('receta.0.insumo_id', (string) $conos->id)
        ->set('receta.0.cantidad_requerida', '1')
        ->call('agregarLineaReceta')
        ->set('receta.1.insumo_id', (string) $chocolate->id)
        ->set('receta.1.cantidad_requerida', '0.250')
        ->call('guardar')
        ->assertHasNoErrors()
        ->assertSet('nombre', '')
        ->assertSet('descripcion', '')
        ->assertSet('precio_venta', '')
        ->assertSet('costo_estimado', '')
        ->assertSet('receta.0.insumo_id', '')
        ->assertSet('receta.0.cantidad_requerida', '')
        ->assertSee('Banana split');

    $producto = Producto::query()->where('nombre', 'Banana split')->first();
    $receta = ProductoInsumo::query()
        ->where('producto_id', $producto?->id)
        ->get();

    expect($producto)->not->toBeNull()
        ->and($producto?->categoria?->nombre)->toBe('Toppings')
        ->and((float) $producto?->precio_venta)->toBe(85.0)
        ->and((float) $producto?->costo_estimado)->toBe(25.25)
        ->and((bool) $producto?->activo)->toBeTrue()
        ->and($receta)->toHaveCount(2);
});

test('puede filtrar por busqueda', function () {
    Livewire::test('productos.index')
        ->set('search', 'Malteada')
        ->assertSee('Malteada')
        ->assertDontSee('Cono doble');
});

test('puede activar y desactivar producto', function () {
    $producto = Producto::query()->where('nombre', 'Cono sencillo')->firstOrFail();

    Livewire::test('productos.index')
        ->call('toggleActivo', $producto->id)
        ->assertDontSee('Cono sencillo')
        ->call('filtrarEstado', 'inactivos')
        ->assertSet('estadoFilter', 'inactivos');

    expect((bool) $producto->fresh()->activo)->toBeFalse();

    Livewire::test('productos.index')
        ->call('filtrarEstado', 'inactivos')
        ->call('toggleActivo', $producto->id)
        ->assertDontSee('Cono sencillo')
        ->call('filtrarEstado', 'activos')
        ->assertSet('estadoFilter', 'activos');

    expect((bool) $producto->fresh()->activo)->toBeTrue();
});

test('productos inactivos se ocultan por defecto y se pueden filtrar', function () {
    $producto = Producto::query()->where('nombre', 'Cono sencillo')->firstOrFail();
    $producto->update(['activo' => false]);

    $component = Livewire::test('productos.index')
        ->assertSet('estadoFilter', 'activos')
        ->assertDontSee('Cono sencillo')
        ->call('filtrarEstado', 'todos')
        ->assertSet('estadoFilter', 'todos');

    $component
        ->call('filtrarEstado', 'inactivos')
        ->assertSet('estadoFilter', 'inactivos');
});

test('puede editar precio y costo de un producto existente', function () {
    $producto = Producto::query()->where('nombre', 'Cono sencillo')->firstOrFail();
    $categoria = CategoriaProducto::query()->where('nombre', 'Toppings')->firstOrFail();

    Livewire::test('productos.index')
        ->call('editarProducto', $producto->id)
        ->assertSet('editing_producto_id', (string) $producto->id)
        ->assertSet('nombre', 'Cono sencillo')
        ->set('categoria_producto_id', (string) $categoria->id)
        ->set('precio_venta', '42.50')
        ->set('costo_estimado', '11.25')
        ->call('guardar')
        ->assertHasNoErrors()
        ->assertSet('editing_producto_id', '')
        ->assertSee('$42.50');

    expect($producto->fresh())
        ->categoria_producto_id->toBe($categoria->id)
        ->and((float) $producto->fresh()->precio_venta)->toBe(42.5)
        ->and((float) $producto->fresh()->costo_estimado)->toBe(11.25);
});

test('filtrar por categoria no cambia la categoria del formulario', function () {
    $helados = CategoriaProducto::query()->where('nombre', 'Helados')->firstOrFail();
    $toppings = CategoriaProducto::query()->where('nombre', 'Toppings')->firstOrFail();

    Livewire::test('productos.index')
        ->set('categoria_producto_id', (string) $helados->id)
        ->set('filtro_categoria_producto_id', (string) $toppings->id)
        ->assertSet('categoria_producto_id', (string) $helados->id)
        ->assertSet('filtro_categoria_producto_id', (string) $toppings->id);
});

test('puede crear producto configurable con grupo sabores desde alta de productos', function () {
    $categoria = CategoriaProducto::query()->where('nombre', 'Helados')->firstOrFail();

    Livewire::test('productos.index')
        ->set('categoria_producto_id', (string) $categoria->id)
        ->set('nombre', 'Nieve doble')
        ->set('precio_venta', '45')
        ->set('costo_estimado', '0')
        ->set('product_type', 'configurable')
        ->set('option_group_name', 'Sabores')
        ->set('required_quantity', '2')
        ->call('guardar')
        ->assertHasNoErrors()
        ->assertSee('Nieve doble');

    $producto = Producto::query()->where('nombre', 'Nieve doble')->firstOrFail();
    $grupo = ProductOptionGroup::query()->where('product_id', $producto->id)->firstOrFail();

    expect($producto->product_type)->toBe('configurable')
        ->and($grupo->name)->toBe('Sabores')
        ->and((float) $grupo->required_quantity)->toBe(2.0);
});

test('mantiene visible el panel configurable al cambiar el tipo de producto', function () {
    Livewire::test('productos.index')
        ->set('product_type', 'configurable')
        ->assertSee('Producto configurable')
        ->assertSee('Grupo configurable')
        ->assertSee('Selecciones requeridas')
        ->assertDontSee('Receta inicial opcional');
});

test('puede crear producto simple conectado a inventario directo', function () {
    $unit = Unit::query()->create([
        'name' => 'pieza',
        'abbreviation' => 'pz',
        'allows_decimals' => false,
    ]);

    $inventoryItem = InventoryItem::query()->create([
        'unit_id' => $unit->id,
        'name' => 'Paleta mango inventario',
        'current_stock' => 30,
        'average_cost' => 8,
        'allows_decimals' => false,
        'is_consumable' => true,
        'is_active' => true,
    ]);

    Livewire::test('productos.index')
        ->set('nombre', 'Paleta mango')
        ->set('precio_venta', '22')
        ->set('product_type', 'simple')
        ->set('inventory_item_id', (string) $inventoryItem->id)
        ->call('guardar')
        ->assertHasNoErrors()
        ->assertSee('Paleta mango');

    $producto = Producto::query()->where('nombre', 'Paleta mango')->firstOrFail();

    expect($producto->product_type)->toBe('simple')
        ->and($producto->inventory_item_id)->toBe($inventoryItem->id)
        ->and((bool) $inventoryItem->fresh()->is_sellable)->toBeTrue();
});

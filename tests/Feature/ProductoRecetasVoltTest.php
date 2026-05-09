<?php

use App\Models\CategoriaProducto;
use App\Models\InventoryItem;
use App\Models\Insumo;
use App\Models\Producto;
use App\Models\ProductOptionGroup;
use App\Models\ProductOptionItem;
use App\Models\ProductoInsumo;
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

test('usuario autenticado puede ver recetas de productos', function () {
    $user = User::factory()->create();

    $response = $this->actingAs($user)->get(route('productos.recetas'));

    $response->assertOk();
    $response->assertSee('Recetas de productos');
    $response->assertSee('Cono sencillo');
    $response->assertSee('Cono doble');
    $response->assertSee('Malteada');
});

test('puede abrir recetas con un producto preseleccionado desde la ruta', function () {
    $user = User::factory()->create();
    $producto = Producto::query()->where('nombre', 'Cono doble')->firstOrFail();

    $response = $this->actingAs($user)->get(route('productos.recetas', [
        'producto' => $producto->id,
    ]));

    $response->assertOk();
    $response->assertSee('Producto seleccionado');
    $response->assertSee('Cono doble');
    $response->assertSee('Helado de vainilla');
});

test('puede seleccionar producto y ver receta actual', function () {
    $producto = Producto::query()->where('nombre', 'Cono sencillo')->firstOrFail();

    Livewire::test('productos.recetas')
        ->call('seleccionarProducto', $producto->id)
        ->assertSet('producto_id', (string) $producto->id)
        ->assertSee('Conos')
        ->assertSee('Helado de vainilla')
        ->assertSee('Servilletas');
});

test('puede agregar un insumo a un producto sin receta y recalcula costo estimado', function () {
    $categoria = CategoriaProducto::query()->where('nombre', 'Toppings')->firstOrFail();
    $producto = Producto::query()->create([
        'categoria_producto_id' => $categoria->id,
        'nombre' => 'Copa especial',
        'descripcion' => 'Producto sin receta inicial',
        'precio_venta' => 90,
        'costo_estimado' => 0,
        'activo' => true,
    ]);
    $insumo = Insumo::query()->where('nombre', 'Chocolate líquido')->firstOrFail();

    Livewire::test('productos.recetas')
        ->call('seleccionarProducto', $producto->id)
        ->set('insumo_id', (string) $insumo->id)
        ->set('cantidad_requerida', '0.500')
        ->call('agregarInsumo')
        ->assertHasNoErrors()
        ->assertSet('insumo_id', '')
        ->assertSet('cantidad_requerida', '')
        ->assertSee('Chocolate líquido');

    $productoInsumo = ProductoInsumo::query()
        ->where('producto_id', $producto->id)
        ->where('insumo_id', $insumo->id)
        ->first();

    expect($productoInsumo)->not->toBeNull()
        ->and((float) $productoInsumo?->cantidad_requerida)->toBe(0.5)
        ->and((float) $producto->fresh()->costo_estimado)->toBe(47.5);
});

test('puede actualizar cantidad requerida y recalcula costo', function () {
    $producto = Producto::query()->where('nombre', 'Cono sencillo')->firstOrFail();
    $insumo = Insumo::query()->where('nombre', 'Helado de vainilla')->firstOrFail();

    Livewire::test('productos.recetas')
        ->call('seleccionarProducto', $producto->id)
        ->call('actualizarCantidad', $insumo->id, 0.200)
        ->assertSee('Helado de vainilla');

    $pivot = ProductoInsumo::query()
        ->where('producto_id', $producto->id)
        ->where('insumo_id', $insumo->id)
        ->firstOrFail();

    expect((float) $pivot->cantidad_requerida)->toBe(0.2)
        ->and((float) $producto->fresh()->costo_estimado)->toBe(18.7);
});

test('puede quitar un insumo e identifica productos con y sin receta', function () {
    $producto = Producto::query()->where('nombre', 'Malteada')->firstOrFail();
    $insumo = Insumo::query()->where('nombre', 'Servilletas')->firstOrFail();

    Livewire::test('productos.recetas')
        ->call('seleccionarProducto', $producto->id)
        ->call('quitarInsumo', $insumo->id);

    expect(
        ProductoInsumo::query()
            ->where('producto_id', $producto->id)
            ->where('insumo_id', $insumo->id)
            ->exists()
    )->toBeFalse();

    $categoria = CategoriaProducto::query()->where('nombre', 'Combos')->firstOrFail();
    $sinReceta = Producto::query()->create([
        'categoria_producto_id' => $categoria->id,
        'nombre' => 'Combo mini',
        'descripcion' => null,
        'precio_venta' => 55,
        'costo_estimado' => 0,
        'activo' => true,
    ]);

    Livewire::test('productos.recetas')
        ->assertSee('Combo mini')
        ->assertSee('Sin receta');

    expect($sinReceta->insumos()->count())->toBe(0);
});

test('puede configurar un producto simple para descontar inventario directo', function () {
    $unit = Unit::query()->create([
        'name' => 'pieza',
        'abbreviation' => 'pz',
        'allows_decimals' => false,
    ]);

    $inventoryItem = InventoryItem::query()->create([
        'unit_id' => $unit->id,
        'name' => 'Paleta mango inventario',
        'current_stock' => 25,
        'average_cost' => 7,
        'allows_decimals' => false,
        'is_sellable' => true,
        'is_consumable' => true,
        'is_active' => true,
    ]);

    $producto = Producto::query()->create([
        'nombre' => 'Paleta mango',
        'precio_venta' => 22,
        'costo_estimado' => 7,
        'activo' => true,
    ]);

    Livewire::test('productos.recetas')
        ->call('seleccionarProducto', $producto->id)
        ->set('product_type', 'simple')
        ->set('inventory_item_id', (string) $inventoryItem->id)
        ->call('actualizarConfiguracionProducto')
        ->assertHasNoErrors()
        ->assertSee('Simple');

    $producto->refresh();

    expect($producto->product_type)->toBe('simple')
        ->and($producto->inventory_item_id)->toBe($inventoryItem->id);
});

test('puede cambiar categoria y mantener visible la configuracion al pasar a configurable', function () {
    $producto = Producto::query()->where('nombre', 'Cono doble')->firstOrFail();
    $categoria = CategoriaProducto::query()->where('nombre', 'Toppings')->firstOrFail();

    Livewire::test('productos.recetas')
        ->call('seleccionarProducto', $producto->id)
        ->set('categoria_producto_id', (string) $categoria->id)
        ->set('product_type', 'configurable')
        ->assertSee('Guardar cambios')
        ->assertSee('Opciones configurables')
        ->call('actualizarConfiguracionProducto')
        ->assertHasNoErrors();

    $producto->refresh();

    expect($producto->product_type)->toBe('configurable')
        ->and($producto->categoria?->nombre)->toBe('Toppings');
});

test('puede configurar nieve doble con grupo sabores y opciones de inventario', function () {
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
        'precio_venta' => 45,
        'costo_estimado' => 11,
        'activo' => true,
    ]);

    Livewire::test('productos.recetas')
        ->call('seleccionarProducto', $producto->id)
        ->set('product_type', 'configurable')
        ->set('option_group_name', 'Sabores')
        ->set('required_quantity', '2')
        ->set('min_quantity', '2')
        ->set('max_quantity', '2')
        ->call('crearGrupoOpciones')
        ->assertHasNoErrors();

    $group = ProductOptionGroup::query()
        ->where('product_id', $producto->id)
        ->where('name', 'Sabores')
        ->firstOrFail();

    Livewire::test('productos.recetas')
        ->call('seleccionarProducto', $producto->id)
        ->set('product_type', 'configurable')
        ->set('option_inventory_item_id', (string) $fresa->id)
        ->set('option_quantity_per_selection', '1')
        ->call('agregarOpcionGrupo', $group->id)
        ->set('option_inventory_item_id', (string) $vainilla->id)
        ->set('option_quantity_per_selection', '1')
        ->call('agregarOpcionGrupo', $group->id)
        ->assertSee('Cantidad por bola / seleccion')
        ->assertSee('una bola puede consumir 0.120')
        ->assertHasNoErrors();

    expect($producto->fresh()->product_type)->toBe('configurable')
        ->and(ProductOptionItem::query()->where('product_option_group_id', $group->id)->count())->toBe(2);
});

test('puede editar inline el grupo configurable y el consumo por bola de un sabor', function () {
    $unit = Unit::query()->create([
        'name' => 'litro',
        'abbreviation' => 'L',
        'allows_decimals' => true,
    ]);

    $fresa = InventoryItem::query()->create([
        'unit_id' => $unit->id,
        'name' => 'Helado fresa',
        'current_stock' => 10,
        'average_cost' => 6,
        'allows_decimals' => true,
        'is_consumable' => true,
        'is_active' => true,
    ]);

    $producto = Producto::query()->create([
        'nombre' => 'Helado doble',
        'product_type' => 'configurable',
        'precio_venta' => 49,
        'costo_estimado' => 0,
        'activo' => true,
    ]);

    $grupo = ProductOptionGroup::query()->create([
        'product_id' => $producto->id,
        'name' => 'Sabores',
        'required_quantity' => 2,
        'min_quantity' => 2,
        'max_quantity' => 2,
    ]);

    $opcion = ProductOptionItem::query()->create([
        'product_option_group_id' => $grupo->id,
        'inventory_item_id' => $fresa->id,
        'quantity_per_selection' => 0.120,
        'extra_price' => 0,
        'is_active' => true,
    ]);

    Livewire::test('productos.recetas')
        ->call('seleccionarProducto', $producto->id)
        ->call('actualizarGrupoOpciones', $grupo->id, 'name', 'Bolas')
        ->call('actualizarGrupoOpciones', $grupo->id, 'required_quantity', '1')
        ->call('actualizarOpcionConfigurable', $opcion->id, 'quantity_per_selection', '0.080')
        ->call('actualizarOpcionConfigurable', $opcion->id, 'extra_price', '5')
        ->assertHasNoErrors()
        ->assertSee('Por bola')
        ->assertSee('Bolas');

    expect($grupo->fresh()->name)->toBe('Bolas')
        ->and((float) $grupo->fresh()->required_quantity)->toBe(1.0)
        ->and((float) $opcion->fresh()->quantity_per_selection)->toBe(0.08)
        ->and((float) $opcion->fresh()->extra_price)->toBe(5.0);
});

test('puede agregar sabores encontrados por busqueda a un producto configurable', function () {
    $unit = Unit::query()->create([
        'name' => 'litro',
        'abbreviation' => 'L',
        'allows_decimals' => true,
    ]);

    InventoryItem::query()->create([
        'unit_id' => $unit->id,
        'name' => 'Helado de queso con zarzamora',
        'current_stock' => 10,
        'average_cost' => 90,
        'allows_decimals' => true,
        'is_consumable' => true,
        'is_active' => true,
    ]);

    InventoryItem::query()->create([
        'unit_id' => $unit->id,
        'name' => 'Helado chicle rosa',
        'current_stock' => 10,
        'average_cost' => 85,
        'allows_decimals' => true,
        'is_consumable' => true,
        'is_active' => true,
    ]);

    $producto = Producto::query()->create([
        'nombre' => 'Nieve doble',
        'product_type' => 'configurable',
        'precio_venta' => 45,
        'costo_estimado' => 0,
        'activo' => true,
    ]);

    Livewire::test('productos.recetas')
        ->call('seleccionarProducto', $producto->id)
        ->set('product_type', 'configurable')
        ->set('quick_flavor_search', 'Helado')
        ->set('selected_flavor_item_ids', InventoryItem::query()->pluck('id')->map(fn ($id) => (string) $id)->all())
        ->call('agregarSaboresSeleccionados')
        ->assertHasNoErrors();

    $group = ProductOptionGroup::query()
        ->where('product_id', $producto->id)
        ->where('name', 'Sabores')
        ->firstOrFail();

    expect(ProductOptionItem::query()->where('product_option_group_id', $group->id)->count())->toBe(2);
});

test('puede encontrar sabores por categoria de insumo aunque esten en otros', function () {
    $categoriaOtros = \App\Models\CategoriaInsumo::query()->create([
        'nombre' => 'Otros',
        'activo' => true,
    ]);

    $insumo = Insumo::query()->create([
        'categoria_insumo_id' => $categoriaOtros->id,
        'nombre' => 'Helado dulce de leche',
        'unidad_medida' => 'litro',
        'cantidad_actual' => 3.9,
        'cantidad_minima' => 2,
        'costo_unitario' => 100,
        'activo' => true,
    ]);

    $unit = Unit::query()->firstOrCreate([
        'name' => 'litro',
    ], [
        'abbreviation' => 'L',
        'allows_decimals' => true,
    ]);

    $inventoryItem = InventoryItem::query()->create([
        'unit_id' => $unit->id,
        'name' => 'Helado dulce de leche',
        'current_stock' => 3.9,
        'average_cost' => 100,
        'allows_decimals' => true,
        'is_consumable' => true,
        'is_active' => true,
        'legacy_table' => 'insumos',
        'legacy_id' => $insumo->id,
    ]);

    $insumo->update([
        'inventory_item_id' => $inventoryItem->id,
    ]);

    $producto = Producto::query()->create([
        'nombre' => 'Helado doble',
        'product_type' => 'configurable',
        'precio_venta' => 49,
        'costo_estimado' => 0,
        'activo' => true,
    ]);

    Livewire::test('productos.recetas')
        ->call('seleccionarProducto', $producto->id)
        ->set('product_type', 'configurable')
        ->set('quick_flavor_search', 'Otros')
        ->assertSee('Helado dulce de leche')
        ->assertSee('Categoria insumo: Otros');
});

test('puede crear un helado doble de 2 sabores y dejarlo listo para vender', function () {
    $categoria = CategoriaProducto::query()->where('nombre', 'Helados')->firstOrFail();

    $unit = Unit::query()->create([
        'name' => 'bola',
        'abbreviation' => 'bola',
        'allows_decimals' => false,
    ]);

    $fresa = InventoryItem::query()->create([
        'unit_id' => $unit->id,
        'name' => 'Helado fresa',
        'current_stock' => 10,
        'average_cost' => 6,
        'allows_decimals' => false,
        'is_consumable' => true,
        'is_active' => true,
    ]);

    $vainilla = InventoryItem::query()->create([
        'unit_id' => $unit->id,
        'name' => 'Helado vainilla',
        'current_stock' => 10,
        'average_cost' => 5,
        'allows_decimals' => false,
        'is_consumable' => true,
        'is_active' => true,
    ]);

    Livewire::test('productos.index')
        ->set('categoria_producto_id', (string) $categoria->id)
        ->set('nombre', 'Helado doble')
        ->set('descripcion', 'Helado doble con dos sabores a elegir')
        ->set('precio_venta', '49')
        ->set('costo_estimado', '0')
        ->set('product_type', 'configurable')
        ->set('option_group_name', 'Sabores')
        ->set('required_quantity', '2')
        ->call('guardar')
        ->assertHasNoErrors()
        ->assertSee('Helado doble');

    $producto = Producto::query()->where('nombre', 'Helado doble')->firstOrFail();

    Livewire::test('productos.recetas')
        ->call('seleccionarProducto', $producto->id)
        ->set('product_type', 'configurable')
        ->set('quick_flavor_search', 'Helado')
        ->set('selected_flavor_item_ids', [(string) $fresa->id, (string) $vainilla->id])
        ->call('agregarSaboresSeleccionados')
        ->assertHasNoErrors();

    $producto->refresh();

    $grupo = ProductOptionGroup::query()
        ->where('product_id', $producto->id)
        ->where('name', 'Sabores')
        ->firstOrFail();

    expect($producto->product_type)->toBe('configurable')
        ->and($producto->categoria?->nombre)->toBe('Helados')
        ->and((float) $producto->precio_venta)->toBe(49.0)
        ->and($grupo->name)->toBe('Sabores')
        ->and((float) $grupo->required_quantity)->toBe(2.0)
        ->and((float) $grupo->min_quantity)->toBe(2.0)
        ->and((float) $grupo->max_quantity)->toBe(2.0)
        ->and(
            ProductOptionItem::query()
                ->where('product_option_group_id', $grupo->id)
                ->count()
        )->toBe(2);
});

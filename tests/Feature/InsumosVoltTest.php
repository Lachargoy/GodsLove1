<?php

use App\Models\CategoriaInsumo;
use App\Models\Insumo;
use App\Models\InventoryItem;
use App\Models\MovimientoInventario;
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

test('usuario autenticado puede ver insumos', function () {
    $user = User::factory()->create();

    $response = $this->actingAs($user)->get(route('insumos.index'));

    $response->assertOk();
    $response->assertSee('Insumos');
    $response->assertSee('Conos');
    $response->assertSee('Servilletas');
    $response->assertSee('Helado de vainilla');
    $response->assertSee('Leche');
    $response->assertSee('Chocolate líquido');
    $response->assertSee('Administrar categorias');
});

test('puede crear un insumo nuevo desde volt', function () {
    $categoria = CategoriaInsumo::query()->where('nombre', 'Desechables')->firstOrFail();

    Livewire::test('insumos.index')
        ->set('categoria_insumo_id', (string) $categoria->id)
        ->set('nombre', 'Vasos medianos')
        ->set('tipo_uso', 'receta')
        ->set('unidad_medida', 'pieza')
        ->set('cantidad_actual', '80')
        ->set('cantidad_minima', '20')
        ->set('costo_unitario', '1.25')
        ->call('guardar')
        ->assertHasNoErrors()
        ->assertSet('nombre', '')
        ->assertSet('tipo_uso', 'receta')
        ->assertSet('unidad_medida', 'pieza')
        ->assertSet('cantidad_actual', '')
        ->assertSet('cantidad_minima', '')
        ->assertSet('costo_unitario', '')
        ->assertSee('Vasos medianos');

    $insumo = Insumo::query()->where('nombre', 'Vasos medianos')->first();

    expect($insumo)->not->toBeNull()
        ->and($insumo?->categoria?->nombre)->toBe('Desechables')
        ->and((float) $insumo?->cantidad_actual)->toBe(80.0)
        ->and((float) $insumo?->cantidad_minima)->toBe(20.0)
        ->and((float) $insumo?->costo_unitario)->toBe(1.25)
        ->and((bool) $insumo?->activo)->toBeTrue()
        ->and((bool) $insumo?->inventoryItem?->is_sellable)->toBeFalse();
});

test('crear insumo genera inventory item para usarlo como sabor configurable', function () {
    $categoria = CategoriaInsumo::query()->firstOrFail();

    Livewire::test('insumos.index')
        ->set('categoria_insumo_id', (string) $categoria->id)
        ->set('nombre', 'Helado de queso con zarzamora')
        ->set('tipo_uso', 'producto_unico')
        ->set('unidad_medida', 'litro')
        ->set('cantidad_actual', '12')
        ->set('cantidad_minima', '2')
        ->set('costo_unitario', '95')
        ->call('guardar')
        ->assertHasNoErrors();

    $insumo = Insumo::query()->where('nombre', 'Helado de queso con zarzamora')->firstOrFail();
    $inventoryItem = InventoryItem::query()->where('name', 'Helado de queso con zarzamora')->firstOrFail();

    expect($insumo->inventory_item_id)->toBe($inventoryItem->id)
        ->and((float) $inventoryItem->current_stock)->toBe(12.0)
        ->and((float) $inventoryItem->average_cost)->toBe(95.0)
        ->and((bool) $inventoryItem->is_sellable)->toBeTrue()
        ->and((bool) $inventoryItem->is_consumable)->toBeTrue()
        ->and(Unit::query()->where('name', 'litro')->exists())->toBeTrue();
});

test('puede filtrar por busqueda', function () {
    Livewire::test('insumos.index')
        ->set('search', 'Leche')
        ->assertSee('Leche')
        ->assertDontSee('Servilletas');
});

test('puede activar y desactivar insumo', function () {
    $insumo = Insumo::query()->where('nombre', 'Conos')->firstOrFail();

    Livewire::test('insumos.index')
        ->call('toggleActivo', $insumo->id)
        ->assertDontSee('Conos')
        ->call('filtrarEstado', 'inactivos')
        ->assertSee('Conos')
        ->assertSee('Activar');

    expect((bool) $insumo->fresh()->activo)->toBeFalse();

    Livewire::test('insumos.index')
        ->call('filtrarEstado', 'inactivos')
        ->call('toggleActivo', $insumo->id)
        ->assertDontSee('Conos')
        ->call('filtrarEstado', 'activos')
        ->assertSee('Conos')
        ->assertSee('Desactivar');

    expect((bool) $insumo->fresh()->activo)->toBeTrue();
});

test('insumos inactivos se ocultan por defecto y se pueden filtrar', function () {
    $insumo = Insumo::query()->where('nombre', 'Conos')->firstOrFail();
    $insumo->update(['activo' => false]);

    Livewire::test('insumos.index')
        ->assertSet('estadoFilter', 'activos')
        ->assertDontSee('Conos')
        ->call('filtrarEstado', 'todos')
        ->assertSee('Conos')
        ->call('filtrarEstado', 'inactivos')
        ->assertSee('Conos')
        ->assertDontSee('Servilletas');
});

test('puede editar campos de insumo inline y sincroniza inventario', function () {
    $categoria = CategoriaInsumo::query()->where('nombre', 'Otros')->firstOrFail();
    $insumo = Insumo::query()->where('nombre', 'Conos')->firstOrFail();

    Livewire::test('insumos.index')
        ->call('editarInline', $insumo->id, 'nombre')
        ->set('editing_value', 'Conos grandes')
        ->call('guardarEdicionInline')
        ->assertHasNoErrors()
        ->assertSee('Conos grandes')
        ->call('editarInline', $insumo->id, 'cantidad_actual')
        ->set('editing_value', '125')
        ->call('guardarEdicionInline')
        ->assertHasNoErrors()
        ->call('editarInline', $insumo->id, 'categoria_insumo_id')
        ->set('editing_value', (string) $categoria->id)
        ->call('guardarEdicionInline')
        ->assertHasNoErrors();

    $insumo->refresh();

    expect($insumo->nombre)->toBe('Conos grandes')
        ->and((float) $insumo->cantidad_actual)->toBe(125.0)
        ->and($insumo->categoria_insumo_id)->toBe($categoria->id)
        ->and($insumo->inventoryItem?->name)->toBe('Conos grandes')
        ->and((float) $insumo->inventoryItem?->current_stock)->toBe(125.0)
        ->and(MovimientoInventario::query()->count())->toBe(1)
        ->and(MovimientoInventario::query()->firstOrFail()->tipo)->toBe('entrada')
        ->and((float) MovimientoInventario::query()->firstOrFail()->cantidad)->toBe(25.0);
});

test('editar cantidad actual inline a la baja registra salida de ajuste', function () {
    $insumo = Insumo::query()->where('nombre', 'Conos')->firstOrFail();

    Livewire::test('insumos.index')
        ->call('editarInline', $insumo->id, 'cantidad_actual')
        ->set('editing_value', '80')
        ->call('guardarEdicionInline')
        ->assertHasNoErrors();

    $movimiento = MovimientoInventario::query()->firstOrFail();

    expect((float) $insumo->fresh()->cantidad_actual)->toBe(80.0)
        ->and($movimiento->tipo)->toBe('salida')
        ->and((float) $movimiento->cantidad)->toBe(-20.0)
        ->and($movimiento->motivo)->toBe('Ajuste inline de inventario');
});

test('puede editar uso de insumo inline', function () {
    $insumo = Insumo::query()->where('nombre', 'Conos')->firstOrFail();

    Livewire::test('insumos.index')
        ->call('editarInline', $insumo->id, 'tipo_uso')
        ->set('editing_value', 'producto_unico')
        ->call('guardarEdicionInline')
        ->assertHasNoErrors();

    expect((bool) $insumo->fresh()->inventoryItem?->is_sellable)->toBeTrue();
});

test('muestra estado de inventario bajo cuando corresponde', function () {
    $insumo = Insumo::query()->where('nombre', 'Conos')->firstOrFail();

    $insumo->update([
        'cantidad_actual' => 20,
        'cantidad_minima' => 30,
    ]);

    Livewire::test('insumos.index')
        ->assertSee('Conos')
        ->assertSee('Bajo inventario');
});

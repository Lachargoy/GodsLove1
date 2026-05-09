<?php

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

test('usuario autenticado puede ver movimientos de inventario', function () {
    $user = User::factory()->create();

    $response = $this->actingAs($user)->get(route('inventario.movimientos'));

    $response->assertOk();
    $response->assertSee('Movimientos de inventario');
    $response->assertSee('Conos');
    $response->assertSee('Servilletas');
    $response->assertSee('Helado de vainilla');
    $response->assertSee('Compra');
    $response->assertSee('Suma stock');
    $response->assertSee('Ultimos 30 movimientos visibles');
    $response->assertSee('Historial reciente');
    $response->assertSee('Limpiar');
});

test('puede limpiar el formulario de movimientos', function () {
    $user = User::factory()->create();
    $insumo = Insumo::query()->where('nombre', 'Conos')->firstOrFail();

    Livewire::actingAs($user)
        ->test('inventario.movimientos')
        ->set('insumo_id', (string) $insumo->id)
        ->set('tipo', 'salida')
        ->set('cantidad', '5')
        ->set('costo_unitario', '1.80')
        ->set('motivo', 'Ajuste manual')
        ->call('limpiarFormulario')
        ->assertSet('insumo_id', '')
        ->assertSet('tipo', 'entrada')
        ->assertSet('cantidad', '')
        ->assertSet('costo_unitario', '')
        ->assertSet('motivo', '');
});

test('puede registrar entrada de inventario', function () {
    $user = User::factory()->create();
    $insumo = Insumo::query()->where('nombre', 'Conos')->firstOrFail();

    Livewire::actingAs($user)
        ->test('inventario.movimientos')
        ->set('insumo_id', (string) $insumo->id)
        ->set('tipo', 'entrada')
        ->set('cantidad', '50')
        ->set('costo_unitario', '1.80')
        ->set('motivo', 'Compra semanal')
        ->call('registrarMovimiento')
        ->assertHasNoErrors()
        ->assertSet('cantidad', '')
        ->assertSet('costo_unitario', '')
        ->assertSet('motivo', '')
        ->assertSee('Compra semanal');

    expect((float) $insumo->fresh()->cantidad_actual)->toBe(150.0)
        ->and(MovimientoInventario::query()->count())->toBe(1)
        ->and((float) MovimientoInventario::query()->firstOrFail()->cantidad)->toBe(50.0);
});

test('puede registrar salida de inventario', function () {
    $user = User::factory()->create();
    $insumo = Insumo::query()->where('nombre', 'Conos')->firstOrFail();

    Livewire::actingAs($user)
        ->test('inventario.movimientos')
        ->set('insumo_id', (string) $insumo->id)
        ->set('tipo', 'salida')
        ->set('cantidad', '5')
        ->set('motivo', 'Ajuste manual')
        ->call('registrarMovimiento')
        ->assertHasNoErrors()
        ->assertSee('Ajuste manual');

    $movimiento = MovimientoInventario::query()->firstOrFail();

    expect((float) $insumo->fresh()->cantidad_actual)->toBe(95.0)
        ->and($movimiento->tipo)->toBe('salida')
        ->and((float) $movimiento->cantidad)->toBe(-5.0);
});

test('puede registrar merma de inventario', function () {
    $user = User::factory()->create();
    $insumo = Insumo::query()->where('nombre', 'Helado de vainilla')->firstOrFail();

    Livewire::actingAs($user)
        ->test('inventario.movimientos')
        ->set('insumo_id', (string) $insumo->id)
        ->set('tipo', 'merma')
        ->set('cantidad', '1.250')
        ->set('motivo', 'Derrame en preparación')
        ->call('registrarMovimiento')
        ->assertHasNoErrors()
        ->assertSee('Derrame en preparación');

    $movimiento = MovimientoInventario::query()->firstOrFail();

    expect((float) $insumo->fresh()->cantidad_actual)->toBe(8.75)
        ->and($movimiento->tipo)->toBe('merma')
        ->and((float) $movimiento->cantidad)->toBe(-1.25);
});

test('no permite salida mayor al inventario disponible', function () {
    $user = User::factory()->create();
    $insumo = Insumo::query()->where('nombre', 'Conos')->firstOrFail();

    Livewire::actingAs($user)
        ->test('inventario.movimientos')
        ->set('insumo_id', (string) $insumo->id)
        ->set('tipo', 'salida')
        ->set('cantidad', '1000')
        ->set('motivo', 'Salida inválida')
        ->call('registrarMovimiento')
        ->assertHasErrors(['cantidad']);

    expect((float) $insumo->fresh()->cantidad_actual)->toBe(100.0)
        ->and(MovimientoInventario::query()->count())->toBe(0);
});

test('entrada sobre insumo ligado a inventario actualiza costo promedio ponderado', function () {
    $user = User::factory()->create();

    $unit = Unit::query()->create([
        'name' => 'litro',
        'abbreviation' => 'L',
        'allows_decimals' => true,
    ]);

    $insumo = Insumo::query()->where('nombre', 'Helado de vainilla')->firstOrFail();

    $inventoryItem = InventoryItem::query()->create([
        'unit_id' => $unit->id,
        'name' => $insumo->nombre,
        'current_stock' => 10,
        'average_cost' => 80,
        'allows_decimals' => true,
        'is_consumable' => true,
        'is_active' => true,
        'legacy_table' => 'insumos',
        'legacy_id' => $insumo->id,
    ]);

    $insumo->update([
        'inventory_item_id' => $inventoryItem->id,
        'cantidad_actual' => 10,
        'costo_unitario' => 80,
    ]);

    Livewire::actingAs($user)
        ->test('inventario.movimientos')
        ->set('insumo_id', (string) $insumo->id)
        ->set('tipo', 'entrada')
        ->set('cantidad', '5')
        ->set('costo_unitario', '120')
        ->set('motivo', 'Compra con nuevo costo')
        ->call('registrarMovimiento')
        ->assertHasNoErrors()
        ->assertSee('Compra con nuevo costo');

    expect((float) $inventoryItem->fresh()->current_stock)->toBe(15.0)
        ->and((float) $inventoryItem->fresh()->average_cost)->toBe(93.3333)
        ->and((float) $insumo->fresh()->cantidad_actual)->toBe(15.0)
        ->and((float) $insumo->fresh()->costo_unitario)->toBe(93.3333)
        ->and(MovimientoInventario::query()->count())->toBe(1)
        ->and((float) MovimientoInventario::query()->firstOrFail()->costo_unitario)->toBe(93.3333);
});

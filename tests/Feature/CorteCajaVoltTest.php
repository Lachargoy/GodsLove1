<?php

use App\Models\CategoriaGasto;
use App\Models\CorteCaja;
use App\Models\Gasto;
use App\Models\Producto;
use App\Models\User;
use App\Models\Venta;
use App\Services\VentaService;
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

test('usuario autenticado puede ver corte de caja', function () {
    $user = User::factory()->create();

    $response = $this->actingAs($user)->get(route('caja.corte'));

    $response->assertOk();
    $response->assertSee('Corte de caja');
    $response->assertSee('Abrir caja');
    $response->assertSee('Historial de cortes');
});

test('puede abrir caja con monto inicial', function () {
    $user = User::factory()->create();

    Livewire::actingAs($user)
        ->test('caja.corte')
        ->set('monto_inicial', '150')
        ->call('abrirCaja')
        ->assertSet('monto_inicial', '')
        ->assertSee('$150.00');

    $corte = CorteCaja::query()->first();

    expect($corte)->not->toBeNull()
        ->and($corte?->estado)->toBe('abierto')
        ->and((float) $corte?->monto_inicial)->toBe(150.0);
});

test('no puede abrir segunda caja si ya hay una abierta', function () {
    $user = User::factory()->create();

    Livewire::actingAs($user)
        ->test('caja.corte')
        ->set('monto_inicial', '100')
        ->call('abrirCaja');

    Livewire::actingAs($user)
        ->test('caja.corte')
        ->set('monto_inicial', '200')
        ->call('abrirCaja');

    expect(CorteCaja::query()->count())->toBe(1)
        ->and(CorteCaja::query()->where('estado', 'abierto')->count())->toBe(1);
});

test('ventas del turno se reflejan por metodo de pago', function () {
    $user = User::factory()->create();

    Livewire::actingAs($user)
        ->test('caja.corte')
        ->set('monto_inicial', '100')
        ->call('abrirCaja');

    $ventaService = app(VentaService::class);
    $conoSencillo = Producto::query()->where('nombre', 'Cono sencillo')->firstOrFail();
    $conoDoble = Producto::query()->where('nombre', 'Cono doble')->firstOrFail();
    $malteada = Producto::query()->where('nombre', 'Malteada')->firstOrFail();

    $ventaService->crearVenta([
        ['producto_id' => $conoSencillo->id, 'cantidad' => 1],
    ], [
        'user_id' => $user->id,
        'metodo_pago' => 'efectivo',
        'fecha_venta' => now(),
    ]);

    $ventaService->crearVenta([
        ['producto_id' => $conoDoble->id, 'cantidad' => 1],
    ], [
        'user_id' => $user->id,
        'metodo_pago' => 'tarjeta',
        'fecha_venta' => now(),
    ]);

    $ventaService->crearVenta([
        ['producto_id' => $malteada->id, 'cantidad' => 1],
    ], [
        'user_id' => $user->id,
        'metodo_pago' => 'transferencia',
        'fecha_venta' => now(),
    ]);

    $ventaService->crearVenta([
        ['producto_id' => $conoSencillo->id, 'cantidad' => 1],
    ], [
        'user_id' => $user->id,
        'metodo_pago' => 'mixto',
        'fecha_venta' => now(),
    ]);

    Livewire::actingAs($user)
        ->test('caja.corte')
        ->assertSee('Detalle de entradas del turno')
        ->assertSee('Entradas en efectivo')
        ->assertSee('$30.00')
        ->assertSee('Entradas con tarjeta')
        ->assertSee('$45.00')
        ->assertSee('Entradas por transferencia')
        ->assertSee('$60.00')
        ->assertSee('Entradas mixtas')
        ->assertSee('$30.00')
        ->assertSee('Tickets: 4')
        ->assertSee('Cono sencillo')
        ->assertSee('Cono doble')
        ->assertSee('Malteada')
        ->assertSee('Ir a Finanzas y cierres')
        ->assertSee('$165.00');

    expect(Venta::query()->whereNotNull('corte_caja_id')->count())->toBe(4);
});

test('puede cerrar caja capturando monto real y guarda el historial', function () {
    $user = User::factory()->create();
    $categoriaGasto = CategoriaGasto::query()->where('nombre', 'Otros')->firstOrFail();

    Livewire::actingAs($user)
        ->test('caja.corte')
        ->set('monto_inicial', '100')
        ->call('abrirCaja');

    $producto = Producto::query()->where('nombre', 'Cono sencillo')->firstOrFail();

    app(VentaService::class)->crearVenta([
        ['producto_id' => $producto->id, 'cantidad' => 1],
    ], [
        'user_id' => $user->id,
        'metodo_pago' => 'efectivo',
        'fecha_venta' => now(),
    ]);

    $corteAbierto = CorteCaja::query()->where('estado', 'abierto')->firstOrFail();

    Gasto::query()->create([
        'categoria_gasto_id' => $categoriaGasto->id,
        'user_id' => $user->id,
        'corte_caja_id' => $corteAbierto->id,
        'descripcion' => 'Cambio para caja',
        'monto' => 5,
        'tipo' => 'variable',
        'metodo_pago' => 'efectivo',
        'origen' => 'caja_dia',
        'fecha_gasto' => now()->toDateString(),
    ]);

    $component = Livewire::actingAs($user)
        ->test('caja.corte')
        ->assertSee('Detalle de gastos del turno')
        ->assertSee('Cambio para caja')
        ->assertSee('Analisis financiero separado')
        ->assertSee('$5.00');

    $component
        ->set('monto_real', '123')
        ->set('observaciones', 'Faltaron dos pesos.')
        ->call('cerrarCaja')
        ->assertSet('monto_real', '')
        ->assertSet('observaciones', '')
        ->assertSee('cerrado');

    $corte = CorteCaja::query()->firstOrFail();

    expect($corte->fecha_cierre)->not->toBeNull()
        ->and((float) $corte->ventas_efectivo)->toBe(30.0)
        ->and((float) $corte->gastos_turno)->toBe(5.0)
        ->and((float) $corte->monto_esperado)->toBe(125.0)
        ->and((float) $corte->monto_real)->toBe(123.0)
        ->and((float) $corte->diferencia)->toBe(-2.0)
        ->and($corte->estado)->toBe('cerrado')
        ->and($corte->observaciones)->toBe('Faltaron dos pesos.');
});

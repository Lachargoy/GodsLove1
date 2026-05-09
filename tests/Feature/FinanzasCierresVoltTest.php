<?php

use App\Models\CategoriaGasto;
use App\Models\CorteCaja;
use App\Models\Gasto;
use App\Models\Producto;
use App\Models\User;
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

test('usuario autenticado puede ver finanzas y cierres', function () {
    $user = User::factory()->create();

    $response = $this->actingAs($user)->get(route('finanzas.cierres'));

    $response->assertOk();
    $response->assertSee('Finanzas y cierres');
    $response->assertSee('Cierres formales por periodo');
    $response->assertSee('Entradas del periodo');
    $response->assertSee('Salidas y gastos del periodo');
});

test('muestra analisis semanal con ventas, gastos y cortes incluidos', function () {
    $user = User::factory()->create();
    $categoriaGasto = CategoriaGasto::query()->where('nombre', 'Renta')->firstOrFail();

    CorteCaja::query()->create([
        'user_id' => $user->id,
        'fecha_apertura' => now(),
        'estado' => 'abierto',
        'monto_inicial' => 200,
    ]);

    $producto = Producto::query()->where('nombre', 'Cono sencillo')->firstOrFail();

    app(VentaService::class)->crearVenta([
        ['producto_id' => $producto->id, 'cantidad' => 2],
    ], [
        'user_id' => $user->id,
        'metodo_pago' => 'efectivo',
        'fecha_venta' => now(),
    ]);

    $corte = CorteCaja::query()->where('estado', 'abierto')->firstOrFail();

    Gasto::query()->create([
        'categoria_gasto_id' => $categoriaGasto->id,
        'user_id' => $user->id,
        'corte_caja_id' => $corte->id,
        'descripcion' => 'Renta semanal',
        'monto' => 120,
        'tipo' => 'fijo',
        'metodo_pago' => 'efectivo',
        'origen' => 'caja_dia',
        'fecha_gasto' => now()->toDateString(),
    ]);

    Gasto::query()->create([
        'categoria_gasto_id' => $categoriaGasto->id,
        'user_id' => $user->id,
        'descripcion' => 'Congelador inversion',
        'monto' => 1000,
        'tipo' => 'variable',
        'metodo_pago' => 'transferencia',
        'origen' => 'inversion_extra',
        'fecha_gasto' => now()->toDateString(),
    ]);

    $corte->update([
        'fecha_cierre' => now(),
        'ventas_efectivo' => 60,
        'gastos_turno' => 120,
        'monto_esperado' => 140,
        'monto_real' => 140,
        'diferencia' => 0,
        'estado' => 'cerrado',
    ]);

    Livewire::actingAs($user)
        ->test('finanzas.cierres')
        ->call('aplicarPeriodo', 'semana')
        ->assertSee('Esta semana')
        ->assertSee('Cono sencillo')
        ->assertSee('Renta semanal')
        ->assertSee('Congelador inversion')
        ->assertSee('Cortes diarios incluidos')
        ->assertSee('$60.00')
        ->assertSee('$120.00')
        ->assertSee('$1,000.00')
        ->assertSee('$-60.00')
        ->assertSee('Fijos');
});

test('puede cambiar a vista mensual y usar calendario personalizado', function () {
    $user = User::factory()->create();

    Livewire::actingAs($user)
        ->test('finanzas.cierres')
        ->call('aplicarPeriodo', 'mes')
        ->assertSee('Este mes')
        ->set('fecha_desde', now()->startOfMonth()->toDateString())
        ->set('fecha_hasta', now()->endOfMonth()->toDateString())
        ->call('actualizarRango')
        ->assertSee('Rango actual:')
        ->assertSee('Entradas del periodo')
        ->assertSee('Ventas por metodo');
});

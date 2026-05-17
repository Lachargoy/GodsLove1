<?php

use App\Models\CategoriaGasto;
use App\Models\CorteCaja;
use App\Models\Gasto;
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

test('usuario autenticado puede ver gastos', function () {
    $user = User::factory()->create();

    $response = $this->actingAs($user)->get(route('gastos.index'));

    $response->assertOk();
    $response->assertSee('Gastos');
    $response->assertSee('Nuevo gasto');
    $response->assertSee('Historial de gastos');
    $response->assertSee('Administrar categorias');
});

test('puede crear un gasto nuevo desde volt', function () {
    $user = User::factory()->create();
    $categoria = CategoriaGasto::query()->where('nombre', 'Materia prima')->firstOrFail();
    $corte = CorteCaja::query()->create([
        'user_id' => $user->id,
        'fecha_apertura' => now(),
        'monto_inicial' => 100,
        'estado' => 'abierto',
    ]);

    Livewire::actingAs($user)
        ->test('gastos.index')
        ->set('categoria_gasto_id', (string) $categoria->id)
        ->set('descripcion', 'Compra de conos')
        ->set('monto', '120.50')
        ->set('tipo', 'variable')
        ->set('metodo_pago', 'efectivo')
        ->set('fecha_gasto', now()->toDateString())
        ->call('guardar')
        ->assertHasNoErrors()
        ->assertSet('descripcion', '')
        ->assertSet('monto', '')
        ->assertSee('Compra de conos');

    $gasto = Gasto::query()->where('descripcion', 'Compra de conos')->first();

    expect($gasto)->not->toBeNull()
        ->and($gasto?->categoria?->nombre)->toBe('Materia prima')
        ->and((float) $gasto?->monto)->toBe(120.5)
        ->and($gasto?->metodo_pago)->toBe('efectivo')
        ->and($gasto?->origen)->toBe('caja_dia')
        ->and($gasto?->user_id)->toBe($user->id)
        ->and($gasto?->corte_caja_id)->toBe($corte->id);
});

test('puede registrar gasto de balance general sin ligarlo a caja', function () {
    $user = User::factory()->create();
    $categoria = CategoriaGasto::query()->where('nombre', 'Otros')->firstOrFail();

    Livewire::actingAs($user)
        ->test('gastos.index')
        ->set('categoria_gasto_id', (string) $categoria->id)
        ->set('descripcion', 'Pago de dominio')
        ->set('monto', '300')
        ->set('tipo', 'fijo')
        ->set('metodo_pago', 'transferencia')
        ->set('origen', 'balance_general')
        ->set('fecha_gasto', now()->toDateString())
        ->call('guardar')
        ->assertHasNoErrors()
        ->assertSee('Pago de dominio');

    $gasto = Gasto::query()->where('descripcion', 'Pago de dominio')->first();

    expect($gasto)->not->toBeNull()
        ->and($gasto?->origen)->toBe('balance_general')
        ->and($gasto?->corte_caja_id)->toBeNull();
});

test('puede registrar inversion extra sin afectar caja del dia', function () {
    $user = User::factory()->create();
    $categoria = CategoriaGasto::query()->where('nombre', 'Otros')->firstOrFail();

    Livewire::actingAs($user)
        ->test('gastos.index')
        ->set('categoria_gasto_id', (string) $categoria->id)
        ->set('descripcion', 'Vitrina nueva')
        ->set('monto', '4500')
        ->set('tipo', 'variable')
        ->set('metodo_pago', 'transferencia')
        ->set('origen', 'inversion_extra')
        ->set('fecha_gasto', now()->toDateString())
        ->call('guardar')
        ->assertHasNoErrors()
        ->assertSee('Vitrina nueva')
        ->assertSee('Inversion extra');

    $gasto = Gasto::query()->where('descripcion', 'Vitrina nueva')->first();

    expect($gasto)->not->toBeNull()
        ->and($gasto?->origen)->toBe('inversion_extra')
        ->and($gasto?->corte_caja_id)->toBeNull();
});

test('gasto de caja del dia debe ser efectivo para cuadrar el corte', function () {
    $user = User::factory()->create();
    $categoria = CategoriaGasto::query()->where('nombre', 'Otros')->firstOrFail();

    CorteCaja::query()->create([
        'user_id' => $user->id,
        'fecha_apertura' => now(),
        'monto_inicial' => 100,
        'estado' => 'abierto',
    ]);

    Livewire::actingAs($user)
        ->test('gastos.index')
        ->set('categoria_gasto_id', (string) $categoria->id)
        ->set('descripcion', 'Compra con tarjeta')
        ->set('monto', '80')
        ->set('tipo', 'variable')
        ->set('metodo_pago', 'tarjeta')
        ->set('origen', 'caja_dia')
        ->set('fecha_gasto', now()->toDateString())
        ->call('guardar')
        ->assertHasErrors(['metodo_pago']);

    expect(Gasto::query()->where('descripcion', 'Compra con tarjeta')->exists())->toBeFalse();
});

test('puede filtrar gastos por busqueda', function () {
    $user = User::factory()->create();
    $categoria = CategoriaGasto::query()->where('nombre', 'Otros')->firstOrFail();

    Gasto::query()->create([
        'categoria_gasto_id' => $categoria->id,
        'user_id' => $user->id,
        'descripcion' => 'Limpieza general',
        'monto' => 80,
        'tipo' => 'variable',
        'metodo_pago' => 'efectivo',
        'fecha_gasto' => now()->toDateString(),
    ]);

    Gasto::query()->create([
        'categoria_gasto_id' => $categoria->id,
        'user_id' => $user->id,
        'descripcion' => 'Mantenimiento congelador',
        'monto' => 300,
        'tipo' => 'fijo',
        'metodo_pago' => 'transferencia',
        'fecha_gasto' => now()->toDateString(),
    ]);

    Livewire::actingAs($user)
        ->test('gastos.index')
        ->set('search', 'Limpieza')
        ->assertSee('Limpieza general')
        ->assertDontSee('Mantenimiento congelador');
});

test('muestra resumenes de gastos del dia y del mes', function () {
    $user = User::factory()->create();
    $categoria = CategoriaGasto::query()->where('nombre', 'Luz')->firstOrFail();

    Gasto::query()->create([
        'categoria_gasto_id' => $categoria->id,
        'user_id' => $user->id,
        'descripcion' => 'Pago de luz',
        'monto' => 500,
        'tipo' => 'fijo',
        'metodo_pago' => 'transferencia',
        'fecha_gasto' => now()->toDateString(),
    ]);

    Livewire::actingAs($user)
        ->test('gastos.index')
        ->assertSee('$500.00')
        ->assertSee('Pago de luz');
});

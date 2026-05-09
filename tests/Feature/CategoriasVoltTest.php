<?php

use App\Models\CategoriaGasto;
use App\Models\CategoriaInsumo;
use App\Models\CategoriaProducto;
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

test('usuario autenticado puede ver categorias', function () {
    $user = User::factory()->create();

    $response = $this->actingAs($user)->get(route('categorias.index'));

    $response->assertOk();
    $response->assertSee('Categorias');
    $response->assertSee('Productos');
    $response->assertSee('Insumos');
    $response->assertSee('Gastos');
});

test('puede crear categoria de producto desde volt', function () {
    Livewire::test('categorias.index')
        ->set('producto_nombre', 'Especialidades frias')
        ->set('producto_descripcion', 'Productos frios individuales')
        ->call('guardarCategoriaProducto')
        ->assertHasNoErrors()
        ->assertSet('producto_nombre', '')
        ->assertSee('Especialidades frias');

    $categoria = CategoriaProducto::query()->where('nombre', 'Especialidades frias')->first();

    expect($categoria)->not->toBeNull()
        ->and($categoria?->descripcion)->toBe('Productos frios individuales')
        ->and((bool) $categoria?->activo)->toBeTrue();
});

test('puede crear categoria de insumo desde volt', function () {
    Livewire::test('categorias.index')
        ->set('insumo_nombre', 'Bases premium')
        ->set('insumo_descripcion', 'Bases para preparacion')
        ->call('guardarCategoriaInsumo')
        ->assertHasNoErrors()
        ->assertSet('insumo_nombre', '')
        ->assertSee('Bases premium');

    $categoria = CategoriaInsumo::query()->where('nombre', 'Bases premium')->first();

    expect($categoria)->not->toBeNull()
        ->and($categoria?->descripcion)->toBe('Bases para preparacion')
        ->and((bool) $categoria?->activo)->toBeTrue();
});

test('puede crear categoria de gasto desde volt', function () {
    Livewire::test('categorias.index')
        ->set('gasto_nombre', 'Marketing')
        ->set('gasto_descripcion', 'Publicidad y promocion')
        ->call('guardarCategoriaGasto')
        ->assertHasNoErrors()
        ->assertSet('gasto_nombre', '')
        ->assertSee('Marketing');

    $categoria = CategoriaGasto::query()->where('nombre', 'Marketing')->first();

    expect($categoria)->not->toBeNull()
        ->and($categoria?->descripcion)->toBe('Publicidad y promocion')
        ->and((bool) $categoria?->activo)->toBeTrue();
});

test('puede editar categoria de producto desde volt', function () {
    $categoria = CategoriaProducto::query()->where('nombre', 'Helados')->firstOrFail();

    Livewire::test('categorias.index')
        ->call('editarCategoriaProducto', $categoria->id)
        ->set('producto_nombre', 'Helados premium')
        ->set('producto_descripcion', 'Linea principal de helados')
        ->call('actualizarCategoriaProducto')
        ->assertHasNoErrors()
        ->assertSet('producto_nombre', '')
        ->assertSee('Helados premium');

    expect($categoria->fresh()->nombre)->toBe('Helados premium')
        ->and($categoria->fresh()->descripcion)->toBe('Linea principal de helados');
});

test('puede activar y desactivar categoria de insumo desde volt', function () {
    $categoria = CategoriaInsumo::query()->where('nombre', 'Desechables')->firstOrFail();

    Livewire::test('categorias.index')
        ->call('toggleCategoriaInsumo', $categoria->id)
        ->assertHasNoErrors();

    expect((bool) $categoria->fresh()->activo)->toBeFalse();

    Livewire::test('categorias.index')
        ->call('toggleCategoriaInsumo', $categoria->id)
        ->assertHasNoErrors();

    expect((bool) $categoria->fresh()->activo)->toBeTrue();
});

test('puede editar y desactivar categoria de gasto desde volt', function () {
    $categoria = CategoriaGasto::query()->where('nombre', 'Renta')->firstOrFail();

    Livewire::test('categorias.index')
        ->call('editarCategoriaGasto', $categoria->id)
        ->set('gasto_nombre', 'Renta local')
        ->set('gasto_descripcion', 'Pago mensual del local')
        ->call('actualizarCategoriaGasto')
        ->assertHasNoErrors()
        ->call('toggleCategoriaGasto', $categoria->id)
        ->assertHasNoErrors();

    expect($categoria->fresh()->nombre)->toBe('Renta local')
        ->and($categoria->fresh()->descripcion)->toBe('Pago mensual del local')
        ->and((bool) $categoria->fresh()->activo)->toBeFalse();
});

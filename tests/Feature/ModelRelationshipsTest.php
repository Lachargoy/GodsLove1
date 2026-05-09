<?php

use App\Models\Insumo;
use App\Models\MovimientoInventario;
use App\Models\Producto;
use App\Models\Venta;
use App\Models\VentaDetalle;
use Database\Seeders\CategoriaGastoSeeder;
use Database\Seeders\CategoriaInsumoSeeder;
use Database\Seeders\CategoriaProductoSeeder;
use Database\Seeders\InsumoSeeder;
use Database\Seeders\ProductoInsumoSeeder;
use Database\Seeders\ProductoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

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

test('producto puede cargar categoria e insumos', function () {
    $producto = Producto::query()
        ->with(['categoria', 'insumos'])
        ->where('nombre', 'Cono sencillo')
        ->firstOrFail();

    expect($producto->categoria)->not->toBeNull()
        ->and($producto->categoria->nombre)->toBe('Helados')
        ->and($producto->insumos)->toHaveCount(3);
});

test('categoria producto puede cargar productos', function () {
    $categoria = \App\Models\CategoriaProducto::query()
        ->with('productos')
        ->where('nombre', 'Helados')
        ->firstOrFail();

    expect($categoria->productos->pluck('nombre')->all())
        ->toContain('Cono sencillo', 'Cono doble');
});

test('insumo puede cargar categoria y productos', function () {
    $insumo = Insumo::query()
        ->with(['categoria', 'productos'])
        ->where('nombre', 'Helado de vainilla')
        ->firstOrFail();

    expect($insumo->categoria)->not->toBeNull()
        ->and($insumo->categoria->nombre)->toBe('Lácteos')
        ->and($insumo->productos->pluck('nombre')->all())
        ->toContain('Cono sencillo', 'Cono doble', 'Malteada');
});

test('venta puede cargar detalles y venta detalle puede cargar producto', function () {
    $producto = Producto::query()->where('nombre', 'Cono sencillo')->firstOrFail();

    $venta = Venta::query()->create([
        'folio' => 'V-999999',
        'subtotal' => 30,
        'descuento' => 0,
        'total' => 30,
        'metodo_pago' => 'efectivo',
        'estado' => 'pagada',
        'fecha_venta' => now(),
    ]);

    $detalle = $venta->detalles()->create([
        'producto_id' => $producto->id,
        'cantidad' => 1,
        'precio_unitario' => 30,
        'costo_unitario_estimado' => 12,
        'subtotal' => 30,
    ]);

    $venta->load('detalles');
    $detalle->load('producto');

    expect($venta->detalles)->toHaveCount(1)
        ->and($detalle->producto)->not->toBeNull()
        ->and($detalle->producto->nombre)->toBe('Cono sencillo');
});

test('movimiento inventario puede cargar insumo', function () {
    $insumo = Insumo::query()->where('nombre', 'Conos')->firstOrFail();

    $movimiento = MovimientoInventario::query()->create([
        'insumo_id' => $insumo->id,
        'tipo' => 'entrada',
        'cantidad' => 10,
        'costo_unitario' => 1.5,
        'fecha_movimiento' => now(),
    ]);

    $movimiento->load('insumo');

    expect($movimiento->insumo)->not->toBeNull()
        ->and($movimiento->insumo->nombre)->toBe('Conos');
});

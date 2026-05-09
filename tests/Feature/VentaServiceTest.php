<?php

use App\Models\Insumo;
use App\Models\MovimientoInventario;
use App\Models\Producto;
use App\Models\CorteCaja;
use App\Models\Venta;
use App\Models\VentaDetalle;
use App\Services\VentaService;
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

test('crea una venta de dos conos sencillos', function () {
    $service = app(VentaService::class);
    $producto = Producto::query()->where('nombre', 'Cono sencillo')->firstOrFail();
    CorteCaja::query()->create([
        'fecha_apertura' => now(),
        'monto_inicial' => 100,
        'estado' => 'abierto',
    ]);

    $venta = $service->crearVenta([
        [
            'producto_id' => $producto->id,
            'cantidad' => 2,
        ],
    ]);

    $conos = Insumo::query()->where('nombre', 'Conos')->firstOrFail();
    $helado = Insumo::query()->where('nombre', 'Helado de vainilla')->firstOrFail();
    $servilletas = Insumo::query()->where('nombre', 'Servilletas')->firstOrFail();

    expect($venta->exists)->toBeTrue()
        ->and($venta->detalles)->toHaveCount(1)
        ->and((float) $venta->subtotal)->toBe(60.0)
        ->and((float) $venta->total)->toBe(60.0)
        ->and($venta->metodo_pago)->toBe('efectivo')
        ->and($venta->folio)->toStartWith('V-')
        ->and((float) $conos->cantidad_actual)->toBe(98.0)
        ->and((float) $helado->cantidad_actual)->toBe(9.76)
        ->and((float) $servilletas->cantidad_actual)->toBe(198.0)
        ->and(MovimientoInventario::query()->where('tipo', 'venta')->count())->toBe(3);
});

test('asocia la venta a la caja abierta si existe', function () {
    $service = app(VentaService::class);
    $producto = Producto::query()->where('nombre', 'Cono sencillo')->firstOrFail();
    $corte = CorteCaja::query()->create([
        'fecha_apertura' => now(),
        'monto_inicial' => 100,
        'estado' => 'abierto',
    ]);

    $venta = $service->crearVenta([
        [
            'producto_id' => $producto->id,
            'cantidad' => 1,
        ],
    ]);

    expect($venta->corte_caja_id)->toBe($corte->id);
});

test('crea una venta con descuento válido', function () {
    $service = app(VentaService::class);
    $producto = Producto::query()->where('nombre', 'Cono sencillo')->firstOrFail();
    CorteCaja::query()->create([
        'fecha_apertura' => now(),
        'monto_inicial' => 100,
        'estado' => 'abierto',
    ]);

    $venta = $service->crearVenta(
        items: [
            [
                'producto_id' => $producto->id,
                'cantidad' => 2,
            ],
        ],
        data: [
            'descuento' => 10,
        ],
    );

    expect((float) $venta->subtotal)->toBe(60.0)
        ->and((float) $venta->descuento)->toBe(10.0)
        ->and((float) $venta->total)->toBe(50.0);
});

test('rechaza una venta sin items', function () {
    $service = app(VentaService::class);

    expect(fn () => $service->crearVenta([]))
        ->toThrow(InvalidArgumentException::class, 'La venta debe incluir al menos un item.');

    expect(Venta::query()->count())->toBe(0);
});

test('rechaza una venta con cantidad inválida', function () {
    $service = app(VentaService::class);
    $producto = Producto::query()->where('nombre', 'Cono sencillo')->firstOrFail();

    expect(fn () => $service->crearVenta([
        [
            'producto_id' => $producto->id,
            'cantidad' => 0,
        ],
    ]))->toThrow(InvalidArgumentException::class, 'La cantidad del item en la posición 0 debe ser mayor a cero.');

    expect(Venta::query()->count())->toBe(0);
});

test('rechaza descuento mayor al subtotal', function () {
    $service = app(VentaService::class);
    $producto = Producto::query()->where('nombre', 'Cono sencillo')->firstOrFail();

    expect(fn () => $service->crearVenta(
        items: [
            [
                'producto_id' => $producto->id,
                'cantidad' => 1,
            ],
        ],
        data: [
            'descuento' => 1000,
        ],
    ))->toThrow(InvalidArgumentException::class, 'El descuento no puede ser mayor al subtotal.');

    expect(Venta::query()->count())->toBe(0);
});

test('revierte toda la venta cuando no hay inventario suficiente', function () {
    $service = app(VentaService::class);
    $producto = Producto::query()->where('nombre', 'Cono doble')->firstOrFail();
    CorteCaja::query()->create([
        'fecha_apertura' => now(),
        'monto_inicial' => 100,
        'estado' => 'abierto',
    ]);

    expect(fn () => $service->crearVenta([
        [
            'producto_id' => $producto->id,
            'cantidad' => 1000,
        ],
    ]))->toThrow(RuntimeException::class, 'Inventario insuficiente para el insumo: Conos');

    $conos = Insumo::query()->where('nombre', 'Conos')->firstOrFail();
    $helado = Insumo::query()->where('nombre', 'Helado de vainilla')->firstOrFail();

    expect(Venta::query()->count())->toBe(0)
        ->and(VentaDetalle::query()->count())->toBe(0)
        ->and((float) $conos->cantidad_actual)->toBe(100.0)
        ->and((float) $helado->cantidad_actual)->toBe(10.0)
        ->and(MovimientoInventario::query()->where('tipo', 'venta')->count())->toBe(0);
});

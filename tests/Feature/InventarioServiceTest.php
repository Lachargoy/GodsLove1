<?php

use App\Models\CategoriaProducto;
use App\Models\Insumo;
use App\Models\MovimientoInventario;
use App\Models\Producto;
use App\Models\User;
use App\Services\InventarioService;
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

test('registra una entrada de inventario', function () {
    $service = app(InventarioService::class);
    $conos = Insumo::query()->where('nombre', 'Conos')->firstOrFail();

    expect((float) $conos->cantidad_actual)->toBe(100.0);

    $movimiento = $service->registrarEntrada(
        insumo: $conos,
        cantidad: 50,
        costoUnitario: 1.5,
        motivo: 'Compra de conos',
    );

    expect((float) $conos->fresh()->cantidad_actual)->toBe(150.0)
        ->and($movimiento->tipo)->toBe('entrada')
        ->and((float) $movimiento->cantidad)->toBe(50.0);
});

test('registra una salida de inventario', function () {
    $service = app(InventarioService::class);
    $conos = Insumo::query()->where('nombre', 'Conos')->firstOrFail();

    $movimiento = $service->registrarSalida(
        insumo: $conos,
        cantidad: 5,
        motivo: 'Ajuste manual',
    );

    expect((float) $conos->fresh()->cantidad_actual)->toBe(95.0)
        ->and($movimiento->tipo)->toBe('salida')
        ->and((float) $movimiento->cantidad)->toBe(-5.0);
});

test('no permite inventario negativo', function () {
    $service = app(InventarioService::class);
    $conos = Insumo::query()->where('nombre', 'Conos')->firstOrFail();

    expect(fn () => $service->registrarSalida(
        insumo: $conos,
        cantidad: 1000,
        motivo: 'Salida inválida',
    ))->toThrow(RuntimeException::class, 'Inventario insuficiente para el insumo: Conos');

    expect((float) $conos->fresh()->cantidad_actual)->toBe(100.0)
        ->and(MovimientoInventario::query()->count())->toBe(0);
});

test('descuenta insumos por producto', function () {
    $service = app(InventarioService::class);
    $producto = Producto::query()->where('nombre', 'Cono sencillo')->firstOrFail();
    $user = User::factory()->create();

    $service->descontarInsumosPorProducto(
        producto: $producto,
        cantidadProducto: 2,
        ventaId: 10,
        userId: $user->id,
    );

    $conos = Insumo::query()->where('nombre', 'Conos')->firstOrFail();
    $helado = Insumo::query()->where('nombre', 'Helado de vainilla')->firstOrFail();
    $servilletas = Insumo::query()->where('nombre', 'Servilletas')->firstOrFail();

    expect((float) $conos->cantidad_actual)->toBe(98.0)
        ->and((float) $helado->cantidad_actual)->toBe(9.76)
        ->and((float) $servilletas->cantidad_actual)->toBe(198.0)
        ->and(MovimientoInventario::query()->where('tipo', 'venta')->count())->toBe(3)
        ->and(MovimientoInventario::query()->where('referencia_tipo', 'venta')->count())->toBe(3)
        ->and(MovimientoInventario::query()->where('referencia_id', 10)->count())->toBe(3);
});

test('falla claramente cuando un producto no tiene receta', function () {
    $service = app(InventarioService::class);
    $categoria = CategoriaProducto::query()->where('nombre', 'Helados')->firstOrFail();

    $producto = Producto::query()->create([
        'categoria_producto_id' => $categoria->id,
        'nombre' => 'Prueba sin receta',
        'descripcion' => 'Producto temporal',
        'precio_venta' => 10,
        'costo_estimado' => 4,
        'activo' => true,
    ]);

    expect(fn () => $service->descontarInsumosPorProducto(
        producto: $producto,
        cantidadProducto: 1,
    ))->toThrow(RuntimeException::class, 'El producto Prueba sin receta no tiene receta configurada.');
});

test('devuelve inventario bajo', function () {
    $service = app(InventarioService::class);
    $conos = Insumo::query()->where('nombre', 'Conos')->firstOrFail();

    $conos->update([
        'cantidad_actual' => 20,
        'cantidad_minima' => 30,
    ]);

    $inventarioBajo = $service->obtenerInventarioBajo();

    expect($inventarioBajo->pluck('nombre'))->toContain('Conos');
});

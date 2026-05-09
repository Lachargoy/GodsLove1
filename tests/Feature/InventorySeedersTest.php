<?php

use App\Models\CategoriaGasto;
use App\Models\CategoriaInsumo;
use App\Models\CategoriaProducto;
use App\Models\Insumo;
use App\Models\Producto;
use App\Models\ProductoInsumo;
use Database\Seeders\CategoriaGastoSeeder;
use Database\Seeders\CategoriaInsumoSeeder;
use Database\Seeders\CategoriaProductoSeeder;
use Database\Seeders\InsumoSeeder;
use Database\Seeders\ProductoInsumoSeeder;
use Database\Seeders\ProductoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('inventory seeders create the expected base records', function () {
    $this->seed([
        CategoriaProductoSeeder::class,
        CategoriaInsumoSeeder::class,
        CategoriaGastoSeeder::class,
        ProductoSeeder::class,
        InsumoSeeder::class,
        ProductoInsumoSeeder::class,
    ]);

    expect(CategoriaProducto::query()->whereIn('nombre', ['Helados', 'Malteadas', 'Toppings'])->count())->toBe(3)
        ->and(CategoriaInsumo::query()->whereIn('nombre', ['Desechables', 'Toppings dulces'])->count())->toBe(2)
        ->and(CategoriaGasto::query()->whereIn('nombre', ['Renta', 'Luz', 'Materia prima'])->count())->toBe(3)
        ->and(Producto::query()->count())->toBe(3)
        ->and(Insumo::query()->count())->toBe(5)
        ->and(ProductoInsumo::query()->count())->toBe(10);

    $conoSencillo = Producto::query()
        ->where('nombre', 'Cono sencillo')
        ->firstOrFail();

    $heladoVainilla = Insumo::query()
        ->where('nombre', 'Helado de vainilla')
        ->firstOrFail();

    expect($conoSencillo->categoria->nombre)->toBe('Helados')
        ->and((float) $conoSencillo->precio_venta)->toBe(30.0)
        ->and((float) $conoSencillo->costo_estimado)->toBe(12.0)
        ->and($heladoVainilla->categoria->nombre)->toBe('Lácteos')
        ->and((float) $heladoVainilla->cantidad_actual)->toBe(10.0)
        ->and((float) $heladoVainilla->costo_unitario)->toBe(85.0);
});

test('product recipe seeders create the expected insumos and cantidades', function () {
    $this->seed([
        CategoriaProductoSeeder::class,
        CategoriaInsumoSeeder::class,
        CategoriaGastoSeeder::class,
        ProductoSeeder::class,
        InsumoSeeder::class,
        ProductoInsumoSeeder::class,
    ]);

    $conoSencillo = Producto::query()
        ->where('nombre', 'Cono sencillo')
        ->firstOrFail();

    $conoDoble = Producto::query()
        ->where('nombre', 'Cono doble')
        ->firstOrFail();

    $malteada = Producto::query()
        ->where('nombre', 'Malteada')
        ->firstOrFail();

    $heladoConoSencillo = ProductoInsumo::query()
        ->where('producto_id', $conoSencillo->id)
        ->whereHas('insumo', fn ($query) => $query->where('nombre', 'Helado de vainilla'))
        ->firstOrFail();

    $heladoConoDoble = ProductoInsumo::query()
        ->where('producto_id', $conoDoble->id)
        ->whereHas('insumo', fn ($query) => $query->where('nombre', 'Helado de vainilla'))
        ->firstOrFail();

    $lecheMalteada = ProductoInsumo::query()
        ->where('producto_id', $malteada->id)
        ->whereHas('insumo', fn ($query) => $query->where('nombre', 'Leche'))
        ->firstOrFail();

    expect($conoSencillo->insumos()->count())->toBe(3)
        ->and($conoDoble->insumos()->count())->toBe(3)
        ->and($malteada->insumos()->count())->toBe(4)
        ->and((float) $heladoConoSencillo->cantidad_requerida)->toBe(0.12)
        ->and((float) $heladoConoDoble->cantidad_requerida)->toBe(0.24)
        ->and((float) $lecheMalteada->cantidad_requerida)->toBe(0.3);
});

<?php

use App\Models\Category;
use App\Models\CategoriaInsumo;
use App\Models\CategoriaProducto;
use App\Models\CorteCaja;
use App\Models\InventoryItem;
use App\Models\InventoryMovement;
use App\Models\Insumo;
use App\Models\MovimientoInventario;
use App\Models\Producto;
use App\Models\ProductOptionGroup;
use App\Models\ProductOptionItem;
use App\Models\ProductRecipe;
use App\Models\SaleDetailComponent;
use App\Models\Unit;
use App\Models\Venta;
use App\Models\VentaDetalle;
use App\Services\InventoryEntryService;
use App\Services\SaleStockDeductionService;
use App\Services\VentaService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

test('dry run does not write backfill data and normal run is idempotent', function () {
    $categoriaProducto = CategoriaProducto::query()->create([
        'nombre' => 'Paletas',
        'activo' => true,
    ]);

    $categoriaInsumo = CategoriaInsumo::query()->create([
        'nombre' => 'Paletas de agua',
        'activo' => true,
    ]);

    $producto = Producto::query()->create([
        'categoria_producto_id' => $categoriaProducto->id,
        'nombre' => 'Paleta de limon',
        'precio_venta' => 20,
        'costo_estimado' => 8,
        'activo' => true,
    ]);

    $insumoId = DB::table('insumos')->insertGetId([
        'categoria_insumo_id' => $categoriaInsumo->id,
        'nombre' => 'Paleta limon inventario',
        'unidad_medida' => 'pieza',
        'cantidad_actual' => 10,
        'cantidad_minima' => 2,
        'costo_unitario' => 8,
        'activo' => true,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    DB::table('producto_insumos')->insert([
        'producto_id' => $producto->id,
        'insumo_id' => $insumoId,
        'cantidad_requerida' => 1,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    Artisan::call('inventory:migrate-existing-data', ['--dry-run' => true]);

    expect(InventoryItem::query()->count())->toBe(0);

    Artisan::call('inventory:migrate-existing-data');
    Artisan::call('inventory:migrate-existing-data');

    $producto->refresh();

    expect(Unit::query()->where('name', 'pieza')->exists())->toBeTrue()
        ->and(Category::query()->where('type', 'product')->where('name', 'Paletas')->exists())->toBeTrue()
        ->and(InventoryItem::query()->count())->toBe(1)
        ->and(ProductRecipe::query()->count())->toBe(1)
        ->and($producto->product_type)->toBe('simple')
        ->and($producto->inventory_item_id)->not->toBeNull()
        ->and((float) InventoryItem::query()->firstOrFail()->current_stock)->toBe(10.0);
});

test('inventory entry recalculates weighted average cost', function () {
    $unit = Unit::query()->create([
        'name' => 'litro',
        'abbreviation' => 'L',
        'allows_decimals' => true,
    ]);

    $item = InventoryItem::query()->create([
        'unit_id' => $unit->id,
        'name' => 'Helado vainilla',
        'current_stock' => 10,
        'average_cost' => 20,
        'allows_decimals' => true,
        'is_consumable' => true,
        'is_active' => true,
    ]);

    app(InventoryEntryService::class)->recordPurchase($item, 5, 30);

    $item->refresh();

    expect((float) $item->current_stock)->toBe(15.0)
        ->and((float) $item->average_cost)->toBe(23.3333)
        ->and(InventoryMovement::query()->where('movement_type', 'purchase')->count())->toBe(1)
        ->and((float) InventoryMovement::query()->firstOrFail()->stock_after)->toBe(15.0);
});

test('configurable product discounts selected flavors and records consumed components', function () {
    $unit = Unit::query()->create([
        'name' => 'bola',
        'abbreviation' => 'bola',
        'allows_decimals' => false,
    ]);

    $fresa = InventoryItem::query()->create([
        'unit_id' => $unit->id,
        'name' => 'Nieve fresa',
        'current_stock' => 10,
        'average_cost' => 6,
        'allows_decimals' => false,
        'is_consumable' => true,
        'is_active' => true,
    ]);

    $vainilla = InventoryItem::query()->create([
        'unit_id' => $unit->id,
        'name' => 'Nieve vainilla',
        'current_stock' => 10,
        'average_cost' => 5,
        'allows_decimals' => false,
        'is_consumable' => true,
        'is_active' => true,
    ]);

    $producto = Producto::query()->create([
        'nombre' => 'Nieve doble',
        'product_type' => 'configurable',
        'precio_venta' => 45,
        'costo_estimado' => 11,
        'activo' => true,
    ]);

    $grupo = ProductOptionGroup::query()->create([
        'product_id' => $producto->id,
        'name' => 'Sabores',
        'required_quantity' => 2,
        'min_quantity' => 2,
        'max_quantity' => 2,
    ]);

    $opcionFresa = ProductOptionItem::query()->create([
        'product_option_group_id' => $grupo->id,
        'inventory_item_id' => $fresa->id,
        'quantity_per_selection' => 1,
        'is_active' => true,
    ]);

    $opcionVainilla = ProductOptionItem::query()->create([
        'product_option_group_id' => $grupo->id,
        'inventory_item_id' => $vainilla->id,
        'quantity_per_selection' => 1,
        'is_active' => true,
    ]);

    $venta = Venta::query()->create([
        'folio' => 'V-TEST',
        'subtotal' => 45,
        'total' => 45,
        'metodo_pago' => 'efectivo',
        'estado' => 'pagada',
        'fecha_venta' => now(),
    ]);

    $detalle = VentaDetalle::query()->create([
        'venta_id' => $venta->id,
        'producto_id' => $producto->id,
        'cantidad' => 1,
        'precio_unitario' => 45,
        'costo_unitario_estimado' => 11,
        'subtotal' => 45,
    ]);

    app(SaleStockDeductionService::class)->deductForSaleDetail($detalle, [
        $grupo->id => [
            $opcionFresa->id => 1,
            $opcionVainilla->id => 1,
        ],
    ]);

    expect((float) $fresa->fresh()->current_stock)->toBe(9.0)
        ->and((float) $vainilla->fresh()->current_stock)->toBe(9.0)
        ->and(InventoryMovement::query()->where('movement_type', 'sale')->count())->toBe(2)
        ->and(SaleDetailComponent::query()->count())->toBe(2);
});

test('venta service uses new inventory core for migrated prepared products and mirrors legacy stock', function () {
    $categoriaProducto = CategoriaProducto::query()->create([
        'nombre' => 'Helados',
        'activo' => true,
    ]);

    $categoriaInsumo = CategoriaInsumo::query()->create([
        'nombre' => 'Base helado',
        'activo' => true,
    ]);

    $producto = Producto::query()->create([
        'categoria_producto_id' => $categoriaProducto->id,
        'nombre' => 'Copa vainilla',
        'precio_venta' => 50,
        'costo_estimado' => 20,
        'activo' => true,
    ]);

    $insumo = Insumo::query()->create([
        'categoria_insumo_id' => $categoriaInsumo->id,
        'nombre' => 'Helado vainilla litros',
        'unidad_medida' => 'litro',
        'cantidad_actual' => 10,
        'cantidad_minima' => 2,
        'costo_unitario' => 80,
        'activo' => true,
    ]);

    DB::table('producto_insumos')->insert([
        'producto_id' => $producto->id,
        'insumo_id' => $insumo->id,
        'cantidad_requerida' => 0.250,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    Artisan::call('inventory:migrate-existing-data');

    CorteCaja::query()->create([
        'fecha_apertura' => now(),
        'monto_inicial' => 100,
        'estado' => 'abierto',
    ]);

    $venta = app(VentaService::class)->crearVenta([
        [
            'producto_id' => $producto->id,
            'cantidad' => 2,
        ],
    ]);

    $inventoryItem = InventoryItem::query()
        ->where('legacy_table', 'insumos')
        ->where('legacy_id', $insumo->id)
        ->firstOrFail();

    expect((float) $inventoryItem->fresh()->current_stock)->toBe(9.5)
        ->and((float) $insumo->fresh()->cantidad_actual)->toBe(9.5)
        ->and(InventoryMovement::query()->where('movement_type', 'sale')->count())->toBe(1)
        ->and(MovimientoInventario::query()->where('tipo', 'venta')->count())->toBe(1)
        ->and(SaleDetailComponent::query()->where('sale_detail_id', $venta->detalles->first()->id)->count())->toBe(1);
});

test('venta service discounts configurable product selections with the new inventory core', function () {
    $unit = Unit::query()->create([
        'name' => 'bola',
        'abbreviation' => 'bola',
        'allows_decimals' => false,
    ]);

    $fresa = InventoryItem::query()->create([
        'unit_id' => $unit->id,
        'name' => 'Nieve fresa',
        'current_stock' => 10,
        'average_cost' => 6,
        'allows_decimals' => false,
        'is_consumable' => true,
        'is_active' => true,
    ]);

    $vainilla = InventoryItem::query()->create([
        'unit_id' => $unit->id,
        'name' => 'Nieve vainilla',
        'current_stock' => 10,
        'average_cost' => 5,
        'allows_decimals' => false,
        'is_consumable' => true,
        'is_active' => true,
    ]);

    $producto = Producto::query()->create([
        'nombre' => 'Nieve doble',
        'product_type' => 'configurable',
        'precio_venta' => 45,
        'costo_estimado' => 11,
        'activo' => true,
    ]);

    $grupo = ProductOptionGroup::query()->create([
        'product_id' => $producto->id,
        'name' => 'Sabores',
        'required_quantity' => 2,
        'min_quantity' => 2,
        'max_quantity' => 2,
    ]);

    $opcionFresa = ProductOptionItem::query()->create([
        'product_option_group_id' => $grupo->id,
        'inventory_item_id' => $fresa->id,
        'quantity_per_selection' => 1,
        'is_active' => true,
    ]);

    $opcionVainilla = ProductOptionItem::query()->create([
        'product_option_group_id' => $grupo->id,
        'inventory_item_id' => $vainilla->id,
        'quantity_per_selection' => 1,
        'is_active' => true,
    ]);

    CorteCaja::query()->create([
        'fecha_apertura' => now(),
        'monto_inicial' => 100,
        'estado' => 'abierto',
    ]);

    app(VentaService::class)->crearVenta([
        [
            'producto_id' => $producto->id,
            'cantidad' => 1,
            'selected_options' => [
                $grupo->id => [
                    $opcionFresa->id => 1,
                    $opcionVainilla->id => 1,
                ],
            ],
        ],
    ]);

    expect((float) $fresa->fresh()->current_stock)->toBe(9.0)
        ->and((float) $vainilla->fresh()->current_stock)->toBe(9.0)
        ->and(InventoryMovement::query()->where('movement_type', 'sale')->count())->toBe(2)
        ->and(SaleDetailComponent::query()->count())->toBe(2);
});

test('venta service configurable also mirrors selected flavor discount back to legacy insumos', function () {
    $categoriaInsumo = CategoriaInsumo::query()->create([
        'nombre' => 'Otros',
        'activo' => true,
    ]);

    $insumoFresa = Insumo::query()->create([
        'categoria_insumo_id' => $categoriaInsumo->id,
        'nombre' => 'Helado fresa',
        'unidad_medida' => 'bola',
        'cantidad_actual' => 10,
        'cantidad_minima' => 2,
        'costo_unitario' => 6,
        'activo' => true,
    ]);

    $insumoVainilla = Insumo::query()->create([
        'categoria_insumo_id' => $categoriaInsumo->id,
        'nombre' => 'Helado vainilla',
        'unidad_medida' => 'bola',
        'cantidad_actual' => 10,
        'cantidad_minima' => 2,
        'costo_unitario' => 5,
        'activo' => true,
    ]);

    $unit = Unit::query()->create([
        'name' => 'bola',
        'abbreviation' => 'bola',
        'allows_decimals' => false,
    ]);

    $fresa = InventoryItem::query()->create([
        'unit_id' => $unit->id,
        'name' => 'Helado fresa',
        'current_stock' => 10,
        'average_cost' => 6,
        'allows_decimals' => false,
        'is_consumable' => true,
        'is_active' => true,
        'legacy_table' => 'insumos',
        'legacy_id' => $insumoFresa->id,
    ]);

    $vainilla = InventoryItem::query()->create([
        'unit_id' => $unit->id,
        'name' => 'Helado vainilla',
        'current_stock' => 10,
        'average_cost' => 5,
        'allows_decimals' => false,
        'is_consumable' => true,
        'is_active' => true,
        'legacy_table' => 'insumos',
        'legacy_id' => $insumoVainilla->id,
    ]);

    $insumoFresa->update(['inventory_item_id' => $fresa->id]);
    $insumoVainilla->update(['inventory_item_id' => $vainilla->id]);

    $producto = Producto::query()->create([
        'nombre' => 'Helado doble',
        'product_type' => 'configurable',
        'precio_venta' => 49,
        'costo_estimado' => 11,
        'activo' => true,
    ]);

    $grupo = ProductOptionGroup::query()->create([
        'product_id' => $producto->id,
        'name' => 'Sabores',
        'required_quantity' => 2,
        'min_quantity' => 2,
        'max_quantity' => 2,
    ]);

    $opcionFresa = ProductOptionItem::query()->create([
        'product_option_group_id' => $grupo->id,
        'inventory_item_id' => $fresa->id,
        'quantity_per_selection' => 1,
        'is_active' => true,
    ]);

    $opcionVainilla = ProductOptionItem::query()->create([
        'product_option_group_id' => $grupo->id,
        'inventory_item_id' => $vainilla->id,
        'quantity_per_selection' => 1,
        'is_active' => true,
    ]);

    CorteCaja::query()->create([
        'fecha_apertura' => now(),
        'monto_inicial' => 100,
        'estado' => 'abierto',
    ]);

    app(VentaService::class)->crearVenta([
        [
            'producto_id' => $producto->id,
            'cantidad' => 1,
            'selected_options' => [
                $grupo->id => [
                    $opcionFresa->id => 1,
                    $opcionVainilla->id => 1,
                ],
            ],
        ],
    ]);

    expect((float) $fresa->fresh()->current_stock)->toBe(9.0)
        ->and((float) $vainilla->fresh()->current_stock)->toBe(9.0)
        ->and((float) $insumoFresa->fresh()->cantidad_actual)->toBe(9.0)
        ->and((float) $insumoVainilla->fresh()->cantidad_actual)->toBe(9.0)
        ->and(MovimientoInventario::query()->where('tipo', 'venta')->count())->toBe(2);
});

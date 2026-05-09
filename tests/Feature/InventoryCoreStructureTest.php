<?php

use App\Models\CategoriaGasto;
use App\Models\CategoriaInsumo;
use App\Models\CategoriaProducto;
use App\Models\CorteCaja;
use App\Models\Gasto;
use App\Models\Insumo;
use App\Models\MovimientoInventario;
use App\Models\Producto;
use App\Models\ProductoInsumo;
use App\Models\Venta;
use App\Models\VentaDetalle;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;

uses(RefreshDatabase::class);

test('inventory core tables contain the expected columns', function () {
    expect(Schema::hasColumns('categoria_productos', ['id', 'nombre', 'descripcion', 'activo', 'created_at', 'updated_at']))->toBeTrue()
        ->and(Schema::hasColumns('productos', ['id', 'categoria_producto_id', 'inventory_item_id', 'category_id', 'nombre', 'descripcion', 'precio_venta', 'costo_estimado', 'product_type', 'activo', 'created_at', 'updated_at']))->toBeTrue()
        ->and(Schema::hasColumns('categoria_insumos', ['id', 'nombre', 'descripcion', 'activo', 'created_at', 'updated_at']))->toBeTrue()
        ->and(Schema::hasColumns('insumos', ['id', 'categoria_insumo_id', 'inventory_item_id', 'nombre', 'unidad_medida', 'cantidad_actual', 'cantidad_minima', 'costo_unitario', 'activo', 'created_at', 'updated_at']))->toBeTrue()
        ->and(Schema::hasColumns('producto_insumos', ['id', 'producto_id', 'insumo_id', 'cantidad_requerida', 'created_at', 'updated_at']))->toBeTrue()
        ->and(Schema::hasColumns('ventas', ['id', 'user_id', 'corte_caja_id', 'folio', 'subtotal', 'descuento', 'total', 'metodo_pago', 'estado', 'fecha_venta', 'created_at', 'updated_at']))->toBeTrue()
        ->and(Schema::hasColumns('venta_detalles', ['id', 'venta_id', 'producto_id', 'cantidad', 'precio_unitario', 'costo_unitario_estimado', 'subtotal', 'created_at', 'updated_at']))->toBeTrue()
        ->and(Schema::hasColumns('movimiento_inventarios', ['id', 'insumo_id', 'user_id', 'tipo', 'cantidad', 'costo_unitario', 'referencia_tipo', 'referencia_id', 'motivo', 'fecha_movimiento', 'created_at', 'updated_at']))->toBeTrue()
        ->and(Schema::hasColumns('categoria_gastos', ['id', 'nombre', 'descripcion', 'activo', 'created_at', 'updated_at']))->toBeTrue()
        ->and(Schema::hasColumns('gastos', ['id', 'categoria_gasto_id', 'user_id', 'corte_caja_id', 'descripcion', 'monto', 'tipo', 'metodo_pago', 'origen', 'fecha_gasto', 'comprobante', 'created_at', 'updated_at']))->toBeTrue()
        ->and(Schema::hasColumns('corte_cajas', ['id', 'user_id', 'fecha_apertura', 'fecha_cierre', 'monto_inicial', 'ventas_efectivo', 'ventas_tarjeta', 'ventas_transferencia', 'gastos_turno', 'monto_esperado', 'monto_real', 'diferencia', 'estado', 'observaciones', 'created_at', 'updated_at']))->toBeTrue();
});

test('inventory core models define the expected table names, fillable attributes, and relationships', function () {
    $categoriaProducto = new CategoriaProducto();
    $producto = new Producto();
    $categoriaInsumo = new CategoriaInsumo();
    $insumo = new Insumo();
    $productoInsumo = new ProductoInsumo();
    $venta = new Venta();
    $ventaDetalle = new VentaDetalle();
    $movimientoInventario = new MovimientoInventario();
    $categoriaGasto = new CategoriaGasto();
    $gasto = new Gasto();
    $corteCaja = new CorteCaja();

    expect($categoriaProducto->getTable())->toBe('categoria_productos')
        ->and($producto->getTable())->toBe('productos')
        ->and($categoriaInsumo->getTable())->toBe('categoria_insumos')
        ->and($insumo->getTable())->toBe('insumos')
        ->and($productoInsumo->getTable())->toBe('producto_insumos')
        ->and($venta->getTable())->toBe('ventas')
        ->and($ventaDetalle->getTable())->toBe('venta_detalles')
        ->and($movimientoInventario->getTable())->toBe('movimiento_inventarios')
        ->and($categoriaGasto->getTable())->toBe('categoria_gastos')
        ->and($gasto->getTable())->toBe('gastos')
        ->and($corteCaja->getTable())->toBe('corte_cajas');

    expect($categoriaProducto->getFillable())->toBe(['nombre', 'descripcion', 'activo'])
        ->and($producto->getFillable())->toBe(['categoria_producto_id', 'inventory_item_id', 'category_id', 'nombre', 'descripcion', 'precio_venta', 'costo_estimado', 'product_type', 'activo'])
        ->and($categoriaInsumo->getFillable())->toBe(['nombre', 'descripcion', 'activo'])
        ->and($insumo->getFillable())->toBe(['categoria_insumo_id', 'inventory_item_id', 'nombre', 'unidad_medida', 'cantidad_actual', 'cantidad_minima', 'costo_unitario', 'activo'])
        ->and($productoInsumo->getFillable())->toBe(['producto_id', 'insumo_id', 'cantidad_requerida'])
        ->and($venta->getFillable())->toBe(['user_id', 'corte_caja_id', 'folio', 'subtotal', 'descuento', 'total', 'metodo_pago', 'estado', 'fecha_venta'])
        ->and($ventaDetalle->getFillable())->toBe(['venta_id', 'producto_id', 'cantidad', 'precio_unitario', 'costo_unitario_estimado', 'subtotal'])
        ->and($movimientoInventario->getFillable())->toBe(['insumo_id', 'user_id', 'tipo', 'cantidad', 'costo_unitario', 'referencia_tipo', 'referencia_id', 'motivo', 'fecha_movimiento'])
        ->and($categoriaGasto->getFillable())->toBe(['nombre', 'descripcion', 'activo'])
        ->and($gasto->getFillable())->toBe(['categoria_gasto_id', 'user_id', 'corte_caja_id', 'descripcion', 'monto', 'tipo', 'metodo_pago', 'origen', 'fecha_gasto', 'comprobante'])
        ->and($corteCaja->getFillable())->toBe(['user_id', 'fecha_apertura', 'fecha_cierre', 'monto_inicial', 'ventas_efectivo', 'ventas_tarjeta', 'ventas_transferencia', 'gastos_turno', 'monto_esperado', 'monto_real', 'diferencia', 'estado', 'observaciones']);

    expect($categoriaProducto->productos())->toBeInstanceOf(HasMany::class)
        ->and($producto->categoria())->toBeInstanceOf(BelongsTo::class)
        ->and($producto->ventaDetalles())->toBeInstanceOf(HasMany::class)
        ->and($producto->insumos())->toBeInstanceOf(BelongsToMany::class)
        ->and($producto->insumos()->getTable())->toBe('producto_insumos')
        ->and($producto->insumos()->getPivotColumns())->toContain('cantidad_requerida')
        ->and($categoriaInsumo->insumos())->toBeInstanceOf(HasMany::class)
        ->and($insumo->categoria())->toBeInstanceOf(BelongsTo::class)
        ->and($insumo->productos())->toBeInstanceOf(BelongsToMany::class)
        ->and($insumo->productos()->getTable())->toBe('producto_insumos')
        ->and($insumo->productos()->getPivotColumns())->toContain('cantidad_requerida')
        ->and($insumo->movimientosInventario())->toBeInstanceOf(HasMany::class)
        ->and($productoInsumo->producto())->toBeInstanceOf(BelongsTo::class)
        ->and($productoInsumo->insumo())->toBeInstanceOf(BelongsTo::class)
        ->and($venta->user())->toBeInstanceOf(BelongsTo::class)
        ->and($venta->corteCaja())->toBeInstanceOf(BelongsTo::class)
        ->and($venta->detalles())->toBeInstanceOf(HasMany::class)
        ->and($ventaDetalle->venta())->toBeInstanceOf(BelongsTo::class)
        ->and($ventaDetalle->producto())->toBeInstanceOf(BelongsTo::class)
        ->and($movimientoInventario->insumo())->toBeInstanceOf(BelongsTo::class)
        ->and($movimientoInventario->user())->toBeInstanceOf(BelongsTo::class)
        ->and($categoriaGasto->gastos())->toBeInstanceOf(HasMany::class)
        ->and($gasto->categoria())->toBeInstanceOf(BelongsTo::class)
        ->and($gasto->user())->toBeInstanceOf(BelongsTo::class)
        ->and($gasto->corteCaja())->toBeInstanceOf(BelongsTo::class)
        ->and($corteCaja->user())->toBeInstanceOf(BelongsTo::class);
});

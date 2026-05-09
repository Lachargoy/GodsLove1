<?php

namespace App\Services;

use App\Models\Producto;
use App\Models\CorteCaja;
use App\Models\Venta;
use App\Models\VentaDetalle;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use RuntimeException;

class VentaService
{
    public function __construct(
        private readonly InventarioService $inventarioService,
        private readonly SaleStockDeductionService $saleStockDeductionService,
    ) {
    }

    /**
     * @param  array<int, array{producto_id?: int, cantidad?: int|float, selected_options?: array<int, array<int, int|float>>}>  $items
     * @param  array{user_id?: int|null, metodo_pago?: string|null, descuento?: int|float|null, fecha_venta?: mixed}  $data
     */
    public function crearVenta(array $items, array $data = []): Venta
    {
        if ($items === []) {
            throw new InvalidArgumentException('La venta debe incluir al menos un item.');
        }

        return DB::transaction(function () use ($items, $data): Venta {
            $detalleItems = [];
            $subtotal = 0.0;

            foreach ($items as $index => $item) {
                if (! array_key_exists('producto_id', $item) || ! array_key_exists('cantidad', $item)) {
                    throw new InvalidArgumentException("El item en la posición {$index} debe incluir producto_id y cantidad.");
                }

                $cantidad = (float) $item['cantidad'];

                if ($cantidad <= 0) {
                    throw new InvalidArgumentException("La cantidad del item en la posición {$index} debe ser mayor a cero.");
                }

                $producto = Producto::query()
                    ->whereKey($item['producto_id'])
                    ->where('activo', true)
                    ->first();

                if (! $producto instanceof Producto) {
                    throw new RuntimeException("El producto con ID {$item['producto_id']} no existe o no está activo.");
                }

                $precioUnitario = round((float) $producto->precio_venta, 2);
                $subtotalItem = round($precioUnitario * $cantidad, 2);
                $subtotal += $subtotalItem;

                $detalleItems[] = [
                    'producto' => $producto,
                    'cantidad' => $cantidad,
                    'precio_unitario' => $precioUnitario,
                    'costo_unitario_estimado' => round((float) $producto->costo_estimado, 2),
                    'subtotal' => $subtotalItem,
                    'selected_options' => $item['selected_options'] ?? [],
                ];
            }

            $subtotal = round($subtotal, 2);
            $descuento = round((float) ($data['descuento'] ?? 0), 2);

            if ($descuento < 0) {
                throw new InvalidArgumentException('El descuento no puede ser negativo.');
            }

            if ($descuento > $subtotal) {
                throw new InvalidArgumentException('El descuento no puede ser mayor al subtotal.');
            }

            $venta = Venta::query()->create([
                'user_id' => $data['user_id'] ?? null,
                'corte_caja_id' => $this->obtenerCajaAbiertaDelDia()->id,
                'folio' => $this->generarFolio(),
                'subtotal' => $subtotal,
                'descuento' => $descuento,
                'total' => round($subtotal - $descuento, 2),
                'metodo_pago' => $data['metodo_pago'] ?? 'efectivo',
                'estado' => 'pagada',
                'fecha_venta' => $data['fecha_venta'] ?? now(),
            ]);

            foreach ($detalleItems as $detalleItem) {
                $detalle = $venta->detalles()->create([
                    'producto_id' => $detalleItem['producto']->id,
                    'cantidad' => $detalleItem['cantidad'],
                    'precio_unitario' => $detalleItem['precio_unitario'],
                    'costo_unitario_estimado' => $detalleItem['costo_unitario_estimado'],
                    'subtotal' => $detalleItem['subtotal'],
                ]);

                $this->descontarInventarioPorDetalle(
                    detalle: $detalle,
                    producto: $detalleItem['producto'],
                    selectedOptions: $detalleItem['selected_options'],
                    userId: $data['user_id'] ?? null,
                );
            }

            return $venta->load('detalles.producto');
        });
    }

    private function generarFolio(): string
    {
        $siguienteId = ((int) Venta::query()->max('id')) + 1;

        return 'V-'.str_pad((string) $siguienteId, 6, '0', STR_PAD_LEFT);
    }

    private function obtenerCajaAbiertaDelDia(): CorteCaja
    {
        $corteCaja = CorteCaja::query()
            ->abiertaDelDia()
            ->latest('fecha_apertura')
            ->first();

        if (! $corteCaja instanceof CorteCaja) {
            throw new RuntimeException('No hay una caja del dia abierta. Abre caja antes de registrar ventas.');
        }

        return $corteCaja;
    }

    /**
     * @param  array<int, array<int, int|float>>  $selectedOptions
     */
    private function descontarInventarioPorDetalle(
        VentaDetalle $detalle,
        Producto $producto,
        array $selectedOptions,
        ?int $userId,
    ): void {
        if ($this->usaNuevoNucleoInventario($producto)) {
            $this->saleStockDeductionService->deductForSaleDetail(
                saleDetail: $detalle,
                selectedOptions: $selectedOptions,
                userId: $userId,
            );

            return;
        }

        $this->inventarioService->descontarInsumosPorProducto(
            producto: $producto,
            cantidadProducto: (float) $detalle->cantidad,
            ventaId: $detalle->venta_id,
            userId: $userId,
        );
    }

    private function usaNuevoNucleoInventario(Producto $producto): bool
    {
        return match ($producto->product_type) {
            'simple' => filled($producto->inventory_item_id),
            'prepared' => $producto->productRecipes()->exists(),
            'configurable' => $producto->productOptionGroups()->exists(),
            default => false,
        };
    }
}

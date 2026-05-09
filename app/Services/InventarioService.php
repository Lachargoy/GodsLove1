<?php

namespace App\Services;

use App\Models\Insumo;
use App\Models\MovimientoInventario;
use App\Models\Producto;
use App\Models\Venta;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use RuntimeException;

class InventarioService
{
    public function registrarMovimiento(
        Insumo $insumo,
        string $tipo,
        float $cantidad,
        float $costoUnitario = 0,
        ?int $userId = null,
        ?string $referenciaTipo = null,
        ?int $referenciaId = null,
        ?string $motivo = null,
    ): MovimientoInventario {
        if ($cantidad <= 0) {
            throw new InvalidArgumentException('La cantidad del movimiento debe ser mayor a cero.');
        }

        return DB::transaction(function () use (
            $insumo,
            $tipo,
            $cantidad,
            $costoUnitario,
            $userId,
            $referenciaTipo,
            $referenciaId,
            $motivo,
        ): MovimientoInventario {
            $insumoActualizado = Insumo::query()
                ->lockForUpdate()
                ->findOrFail($insumo->id);

            $cantidadReal = $this->calcularCantidadReal($tipo, $cantidad);
            $nuevaCantidad = round((float) $insumoActualizado->cantidad_actual + $cantidadReal, 3);

            if ($nuevaCantidad < 0) {
                throw new RuntimeException("Inventario insuficiente para el insumo: {$insumoActualizado->nombre}");
            }

            $insumoActualizado->update([
                'cantidad_actual' => $nuevaCantidad,
            ]);

            return MovimientoInventario::query()->create([
                'insumo_id' => $insumoActualizado->id,
                'user_id' => $userId,
                'tipo' => $tipo,
                'cantidad' => $cantidadReal,
                'costo_unitario' => $costoUnitario,
                'referencia_tipo' => $referenciaTipo,
                'referencia_id' => $referenciaId,
                'motivo' => $motivo,
                'fecha_movimiento' => now(),
            ]);
        });
    }

    public function registrarEntrada(
        Insumo $insumo,
        float $cantidad,
        float $costoUnitario = 0,
        ?int $userId = null,
        ?string $motivo = null,
    ): MovimientoInventario {
        return $this->registrarMovimiento(
            insumo: $insumo,
            tipo: 'entrada',
            cantidad: $cantidad,
            costoUnitario: $costoUnitario,
            userId: $userId,
            motivo: $motivo,
        );
    }

    public function registrarSalida(
        Insumo $insumo,
        float $cantidad,
        ?int $userId = null,
        ?string $motivo = null,
    ): MovimientoInventario {
        return $this->registrarMovimiento(
            insumo: $insumo,
            tipo: 'salida',
            cantidad: $cantidad,
            userId: $userId,
            motivo: $motivo,
        );
    }

    public function registrarMerma(
        Insumo $insumo,
        float $cantidad,
        ?int $userId = null,
        ?string $motivo = null,
    ): MovimientoInventario {
        return $this->registrarMovimiento(
            insumo: $insumo,
            tipo: 'merma',
            cantidad: $cantidad,
            userId: $userId,
            motivo: $motivo,
        );
    }

    public function descontarInsumosPorProducto(
        Producto $producto,
        float $cantidadProducto,
        ?int $ventaId = null,
        ?int $userId = null,
    ): void {
        if ($cantidadProducto <= 0) {
            throw new InvalidArgumentException('La cantidad del producto debe ser mayor a cero.');
        }

        DB::transaction(function () use ($producto, $cantidadProducto, $ventaId, $userId): void {
            $productoConInsumos = Producto::query()
                ->with('insumos')
                ->findOrFail($producto->id);

            if ($productoConInsumos->insumos->isEmpty()) {
                throw new RuntimeException("El producto {$productoConInsumos->nombre} no tiene receta configurada.");
            }

            $this->validarInventarioDisponibleParaReceta(
                insumos: $productoConInsumos->insumos,
                cantidadProducto: $cantidadProducto,
            );

            foreach ($productoConInsumos->insumos as $insumo) {
                $cantidadRequerida = (float) $insumo->pivot->cantidad_requerida;
                $cantidadADescontar = round($cantidadRequerida * $cantidadProducto, 3);

                $this->registrarMovimiento(
                    insumo: $insumo,
                    tipo: 'venta',
                    cantidad: $cantidadADescontar,
                    costoUnitario: (float) $insumo->costo_unitario,
                    userId: $userId,
                    referenciaTipo: 'venta',
                    referenciaId: $ventaId,
                    motivo: "Venta de {$cantidadProducto} x {$productoConInsumos->nombre}",
                );
            }
        });
    }

    public function descontarInsumosPorVenta(Venta $venta, ?int $userId = null): void
    {
        DB::transaction(function () use ($venta, $userId): void {
            $ventaConDetalles = Venta::query()
                ->with('detalles.producto.insumos')
                ->findOrFail($venta->id);

            foreach ($ventaConDetalles->detalles as $detalle) {
                $this->descontarInsumosPorProducto(
                    producto: $detalle->producto,
                    cantidadProducto: (float) $detalle->cantidad,
                    ventaId: $ventaConDetalles->id,
                    userId: $userId,
                );
            }
        });
    }

    public function obtenerInventarioBajo(): Collection
    {
        return Insumo::query()
            ->where('activo', true)
            ->whereColumn('cantidad_actual', '<=', 'cantidad_minima')
            ->get();
    }

    private function calcularCantidadReal(string $tipo, float $cantidad): float
    {
        return match ($tipo) {
            'entrada', 'devolucion' => $cantidad,
            'salida', 'venta', 'merma' => -$cantidad,
            default => throw new InvalidArgumentException("Tipo de movimiento de inventario no válido: {$tipo}"),
        };
    }

    /**
     * @param Collection<int, Insumo> $insumos
     */
    private function validarInventarioDisponibleParaReceta(Collection $insumos, float $cantidadProducto): void
    {
        foreach ($insumos as $insumo) {
            $cantidadRequerida = (float) $insumo->pivot->cantidad_requerida;
            $cantidadADescontar = round($cantidadRequerida * $cantidadProducto, 3);
            $cantidadDisponible = round((float) $insumo->cantidad_actual, 3);

            if ($cantidadDisponible < $cantidadADescontar) {
                throw new RuntimeException("Inventario insuficiente para el insumo: {$insumo->nombre}");
            }
        }
    }
}

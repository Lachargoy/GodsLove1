<?php

namespace App\Services;

use App\Models\InventoryItem;
use App\Models\InventoryMovement;
use App\Models\Insumo;
use App\Models\MovimientoInventario;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use RuntimeException;

class InventoryMovementService
{
    public function recordMovement(
        InventoryItem $inventoryItem,
        string $movementType,
        float $quantity,
        ?float $unitCost = null,
        ?int $userId = null,
        ?string $referenceType = null,
        ?int $referenceId = null,
        ?string $notes = null,
    ): InventoryMovement {
        if ($quantity <= 0) {
            throw new InvalidArgumentException('Inventory movement quantity must be greater than zero.');
        }

        return DB::transaction(function () use (
            $inventoryItem,
            $movementType,
            $quantity,
            $unitCost,
            $userId,
            $referenceType,
            $referenceId,
            $notes,
        ): InventoryMovement {
            $item = InventoryItem::query()
                ->lockForUpdate()
                ->findOrFail($inventoryItem->id);

            $signedQuantity = $this->signedQuantity($movementType, $quantity);
            $stockAfter = round((float) $item->current_stock + $signedQuantity, 3);

            if ($stockAfter < 0) {
                throw new RuntimeException("Inventario insuficiente para el insumo: {$item->name}");
            }

            $item->update([
                'current_stock' => $stockAfter,
            ]);

            $movement = InventoryMovement::query()->create([
                'inventory_item_id' => $item->id,
                'user_id' => $userId,
                'movement_type' => $movementType,
                'quantity' => $signedQuantity,
                'unit_cost' => $unitCost,
                'average_cost_after' => (float) $item->average_cost,
                'stock_after' => $stockAfter,
                'reference_type' => $referenceType,
                'reference_id' => $referenceId,
                'notes' => $notes,
            ]);

            $this->syncLegacyInventoryMovement($item, $movement);

            return $movement;
        });
    }

    private function syncLegacyInventoryMovement(InventoryItem $item, InventoryMovement $movement): void
    {
        if ($item->legacy_table !== 'insumos' || ! $item->legacy_id) {
            return;
        }

        $insumo = Insumo::query()
            ->lockForUpdate()
            ->find($item->legacy_id);

        if (! $insumo instanceof Insumo) {
            return;
        }

        $insumo->update([
            'cantidad_actual' => (float) $movement->stock_after,
        ]);

        $legacyMovement = MovimientoInventario::query()->create([
            'insumo_id' => $insumo->id,
            'user_id' => $movement->user_id,
            'tipo' => $this->legacyMovementType($movement->movement_type),
            'cantidad' => (float) $movement->quantity,
            'costo_unitario' => (float) ($movement->unit_cost ?? 0),
            'referencia_tipo' => $movement->reference_type,
            'referencia_id' => $movement->reference_id,
            'motivo' => $movement->notes,
            'fecha_movimiento' => now(),
        ]);

        $movement->update([
            'legacy_movimiento_inventario_id' => $legacyMovement->id,
        ]);
    }

    private function signedQuantity(string $movementType, float $quantity): float
    {
        return match ($movementType) {
            'purchase', 'return', 'manual_in' => round($quantity, 3),
            'sale', 'waste', 'manual_out' => round(-$quantity, 3),
            'adjustment' => round($quantity, 3),
            default => throw new InvalidArgumentException("Invalid inventory movement type: {$movementType}"),
        };
    }

    private function legacyMovementType(string $movementType): string
    {
        return match ($movementType) {
            'purchase', 'manual_in' => 'entrada',
            'manual_out' => 'salida',
            'sale' => 'venta',
            'waste' => 'merma',
            'return' => 'devolucion',
            default => 'salida',
        };
    }
}

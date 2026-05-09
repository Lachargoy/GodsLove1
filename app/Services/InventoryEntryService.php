<?php

namespace App\Services;

use App\Models\InventoryItem;
use App\Models\InventoryMovement;
use App\Models\Insumo;
use App\Models\MovimientoInventario;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class InventoryEntryService
{
    public function recordPurchase(
        InventoryItem $inventoryItem,
        float $quantity,
        float $unitCost,
        ?int $userId = null,
        ?string $notes = null,
    ): InventoryMovement {
        if ($quantity <= 0) {
            throw new InvalidArgumentException('Purchase quantity must be greater than zero.');
        }

        if ($unitCost < 0) {
            throw new InvalidArgumentException('Unit cost cannot be negative.');
        }

        return DB::transaction(function () use ($inventoryItem, $quantity, $unitCost, $userId, $notes): InventoryMovement {
            $item = InventoryItem::query()
                ->lockForUpdate()
                ->findOrFail($inventoryItem->id);

            $currentStock = (float) $item->current_stock;
            $currentAverageCost = (float) $item->average_cost;
            $newStock = round($currentStock + $quantity, 3);
            $newAverageCost = $this->calculateWeightedAverageCost(
                currentStock: $currentStock,
                currentAverageCost: $currentAverageCost,
                entryQuantity: $quantity,
                entryUnitCost: $unitCost,
            );

            $item->update([
                'current_stock' => $newStock,
                'average_cost' => $newAverageCost,
            ]);

            $movement = InventoryMovement::query()->create([
                'inventory_item_id' => $item->id,
                'user_id' => $userId,
                'movement_type' => 'purchase',
                'quantity' => round($quantity, 3),
                'unit_cost' => round($unitCost, 4),
                'average_cost_after' => $newAverageCost,
                'stock_after' => $newStock,
                'notes' => $notes,
            ]);

            $this->syncLegacyInsumoFromPurchase($item, $movement);

            return $movement;
        });
    }

    private function syncLegacyInsumoFromPurchase(InventoryItem $item, InventoryMovement $movement): void
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
            'cantidad_actual' => (float) $item->current_stock,
            'costo_unitario' => (float) $item->average_cost,
            'inventory_item_id' => $item->id,
        ]);

        $legacyMovement = MovimientoInventario::query()->create([
            'insumo_id' => $insumo->id,
            'user_id' => $movement->user_id,
            'tipo' => 'entrada',
            'cantidad' => (float) $movement->quantity,
            'costo_unitario' => (float) ($movement->average_cost_after ?? $movement->unit_cost ?? 0),
            'referencia_tipo' => $movement->reference_type,
            'referencia_id' => $movement->reference_id,
            'motivo' => $movement->notes,
            'fecha_movimiento' => now(),
        ]);

        $movement->update([
            'legacy_movimiento_inventario_id' => $legacyMovement->id,
        ]);
    }

    private function calculateWeightedAverageCost(
        float $currentStock,
        float $currentAverageCost,
        float $entryQuantity,
        float $entryUnitCost,
    ): float {
        $newStock = $currentStock + $entryQuantity;

        if ($newStock <= 0) {
            return round($entryUnitCost, 4);
        }

        return round(
            (($currentStock * $currentAverageCost) + ($entryQuantity * $entryUnitCost)) / $newStock,
            4,
        );
    }
}

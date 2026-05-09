<?php

namespace App\Services;

use App\Models\Producto;
use InvalidArgumentException;
use RuntimeException;

class ProductConfigurationService
{
    /**
     * @param  array<int, array<int, int|float>>  $selectedOptions
     * @return array<int, array{inventory_item_id: int, quantity: float, extra_price: float}>
     */
    public function resolveConfiguration(Producto $product, array $selectedOptions): array
    {
        $product->load('productOptionGroups.optionItems.inventoryItem');

        if ($product->productOptionGroups->isEmpty()) {
            throw new RuntimeException("Product {$product->nombre} does not have configurable option groups.");
        }

        $components = [];

        foreach ($product->productOptionGroups as $group) {
            $groupSelections = $selectedOptions[$group->id] ?? [];
            $selectedQuantity = array_sum(array_map('floatval', $groupSelections));
            $requiredQuantity = (float) $group->required_quantity;
            $minQuantity = (float) ($group->min_quantity ?? $requiredQuantity);
            $maxQuantity = (float) ($group->max_quantity ?? $requiredQuantity);

            if (round($selectedQuantity, 3) < round($minQuantity, 3)) {
                throw new InvalidArgumentException("El grupo {$group->name} requiere al menos ".number_format($minQuantity, 0).' seleccion(es).');
            }

            if (round($selectedQuantity, 3) > round($maxQuantity, 3)) {
                throw new InvalidArgumentException("El grupo {$group->name} permite maximo ".number_format($maxQuantity, 0).' seleccion(es).');
            }

            foreach ($groupSelections as $optionItemId => $selectionQuantity) {
                $optionItem = $group->optionItems->firstWhere('id', (int) $optionItemId);

                if (! $optionItem || ! $optionItem->is_active) {
                    throw new InvalidArgumentException("Invalid option selected for group {$group->name}.");
                }

                $quantity = round((float) $optionItem->quantity_per_selection * (float) $selectionQuantity, 3);

                $components[] = [
                    'inventory_item_id' => $optionItem->inventory_item_id,
                    'quantity' => $quantity,
                    'extra_price' => round((float) ($optionItem->extra_price ?? 0), 2),
                ];
            }
        }

        return $components;
    }
}

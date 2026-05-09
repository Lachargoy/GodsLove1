<?php

namespace App\Services;

use App\Models\InventoryItem;
use App\Models\Producto;
use App\Models\SaleDetailComponent;
use App\Models\VentaDetalle;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class SaleStockDeductionService
{
    public function __construct(
        private readonly InventoryMovementService $inventoryMovementService,
        private readonly ProductConfigurationService $productConfigurationService,
    ) {
    }

    /**
     * @param  array<int, array<int, int|float>>  $selectedOptions
     */
    public function deductForSaleDetail(VentaDetalle $saleDetail, array $selectedOptions = [], ?int $userId = null): void
    {
        DB::transaction(function () use ($saleDetail, $selectedOptions, $userId): void {
            $saleDetail->load('producto');
            $product = $saleDetail->producto;

            if (! $product instanceof Producto) {
                throw new RuntimeException('Sale detail does not have a valid product.');
            }

            $components = match ($product->product_type) {
                'simple' => $this->componentsForSimpleProduct($product, (float) $saleDetail->cantidad),
                'prepared' => $this->componentsForPreparedProduct($product, (float) $saleDetail->cantidad),
                'configurable' => $this->componentsForConfigurableProduct($product, $selectedOptions, (float) $saleDetail->cantidad),
                default => throw new RuntimeException("Unsupported product type: {$product->product_type}"),
            };

            $this->validateStock($components);

            foreach ($components as $component) {
                $item = InventoryItem::query()->findOrFail($component['inventory_item_id']);

                $this->inventoryMovementService->recordMovement(
                    inventoryItem: $item,
                    movementType: 'sale',
                    quantity: $component['quantity'],
                    unitCost: (float) $item->average_cost,
                    userId: $userId,
                    referenceType: 'venta_detalle',
                    referenceId: $saleDetail->id,
                    notes: "Sale of {$saleDetail->cantidad} x {$product->nombre}",
                );

                SaleDetailComponent::query()->create([
                    'sale_detail_id' => $saleDetail->id,
                    'inventory_item_id' => $item->id,
                    'quantity_consumed' => $component['quantity'],
                    'unit_cost_at_sale' => (float) $item->average_cost,
                    'total_cost' => round($component['quantity'] * (float) $item->average_cost, 4),
                ]);
            }
        });
    }

    /**
     * @return array<int, array{inventory_item_id: int, quantity: float}>
     */
    private function componentsForSimpleProduct(Producto $product, float $saleQuantity): array
    {
        if (! $product->inventory_item_id) {
            throw new RuntimeException("Simple product {$product->nombre} does not have an inventory item.");
        }

        return [[
            'inventory_item_id' => (int) $product->inventory_item_id,
            'quantity' => round($saleQuantity, 3),
        ]];
    }

    /**
     * @return array<int, array{inventory_item_id: int, quantity: float}>
     */
    private function componentsForPreparedProduct(Producto $product, float $saleQuantity): array
    {
        $product->load('productRecipes');

        if ($product->productRecipes->isEmpty()) {
            throw new RuntimeException("Prepared product {$product->nombre} does not have a recipe.");
        }

        return $product->productRecipes
            ->map(fn ($recipe): array => [
                'inventory_item_id' => (int) $recipe->inventory_item_id,
                'quantity' => round((float) $recipe->quantity * $saleQuantity, 3),
            ])
            ->values()
            ->all();
    }

    /**
     * @param  array<int, array<int, int|float>>  $selectedOptions
     * @return array<int, array{inventory_item_id: int, quantity: float}>
     */
    private function componentsForConfigurableProduct(Producto $product, array $selectedOptions, float $saleQuantity): array
    {
        return collect($this->productConfigurationService->resolveConfiguration($product, $selectedOptions))
            ->map(fn (array $component): array => [
                'inventory_item_id' => (int) $component['inventory_item_id'],
                'quantity' => round((float) $component['quantity'] * $saleQuantity, 3),
            ])
            ->values()
            ->all();
    }

    /**
     * @param  array<int, array{inventory_item_id: int, quantity: float}>  $components
     */
    private function validateStock(array $components): void
    {
        foreach ($components as $component) {
            $item = InventoryItem::query()->findOrFail($component['inventory_item_id']);

            if ((float) $item->current_stock < $component['quantity']) {
                throw new RuntimeException("Insufficient inventory for item: {$item->name}");
            }
        }
    }
}

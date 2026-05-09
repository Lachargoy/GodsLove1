<?php

namespace App\Console\Commands;

use App\Models\Category;
use App\Models\InventoryItem;
use App\Models\InventoryMovement;
use App\Models\ProductRecipe;
use App\Models\Producto;
use App\Models\Unit;
use Database\Seeders\InventoryCategorySeeder;
use Database\Seeders\UnitSeeder;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

#[Signature('inventory:migrate-existing-data {--dry-run : Show what would be migrated without writing data}')]
#[Description('Migrate legacy products, inputs, recipes, and inventory movements into the new inventory core.')]
class MigrateExistingInventoryData extends Command
{
    public function handle(): int
    {
        $dryRun = (bool) $this->option('dry-run');

        $summary = [
            'legacy_product_categories' => DB::table('categoria_productos')->count(),
            'legacy_inventory_categories' => DB::table('categoria_insumos')->count(),
            'legacy_expense_categories' => DB::table('categoria_gastos')->count(),
            'legacy_inventory_items' => DB::table('insumos')->count(),
            'legacy_products' => DB::table('productos')->count(),
            'legacy_recipes' => DB::table('producto_insumos')->count(),
            'legacy_movements' => DB::table('movimiento_inventarios')->count(),
        ];

        $this->info($dryRun ? 'DRY RUN: no data will be written.' : 'Migrating existing inventory data.');
        $this->table(['Source', 'Rows'], collect($summary)->map(fn (int $count, string $source): array => [$source, $count])->all());

        if ($dryRun) {
            $this->warn('Run without --dry-run to write idempotent backfill records.');

            return self::SUCCESS;
        }

        DB::transaction(function (): void {
            app(UnitSeeder::class)->run();
            app(InventoryCategorySeeder::class)->run();

            $this->migrateCategories('categoria_productos', 'product');
            $this->migrateCategories('categoria_insumos', 'inventory_item');
            $this->migrateCategories('categoria_gastos', 'expense');
            $this->migrateInventoryItems();
            $this->migrateProducts();
            $this->migrateRecipes();
            $this->migrateLegacyMovements();
        });

        $this->info('Inventory backfill completed safely.');

        return self::SUCCESS;
    }

    private function migrateCategories(string $legacyTable, string $type): void
    {
        DB::table($legacyTable)
            ->orderBy('id')
            ->get()
            ->each(function (object $legacyCategory) use ($legacyTable, $type): void {
                Category::query()->updateOrCreate(
                    [
                        'legacy_table' => $legacyTable,
                        'legacy_id' => $legacyCategory->id,
                    ],
                    [
                        'name' => $legacyCategory->nombre,
                        'type' => $type,
                        'is_active' => (bool) $legacyCategory->activo,
                    ],
                );
            });
    }

    private function migrateInventoryItems(): void
    {
        DB::table('insumos')
            ->orderBy('id')
            ->get()
            ->each(function (object $legacyInput): void {
                $categoryId = $legacyInput->categoria_insumo_id
                    ? Category::query()
                        ->where('legacy_table', 'categoria_insumos')
                        ->where('legacy_id', $legacyInput->categoria_insumo_id)
                        ->value('id')
                    : null;

                $unit = $this->unitForLegacyName((string) $legacyInput->unidad_medida);

                $inventoryItem = InventoryItem::query()->updateOrCreate(
                    [
                        'legacy_table' => 'insumos',
                        'legacy_id' => $legacyInput->id,
                    ],
                    [
                        'category_id' => $categoryId,
                        'unit_id' => $unit->id,
                        'name' => $legacyInput->nombre,
                        'current_stock' => (float) $legacyInput->cantidad_actual,
                        'minimum_stock' => (float) $legacyInput->cantidad_minima,
                        'average_cost' => (float) $legacyInput->costo_unitario,
                        'allows_decimals' => (bool) $unit->allows_decimals,
                        'is_sellable' => false,
                        'is_consumable' => true,
                        'is_active' => (bool) $legacyInput->activo,
                    ],
                );

                DB::table('insumos')
                    ->where('id', $legacyInput->id)
                    ->update(['inventory_item_id' => $inventoryItem->id]);
            });
    }

    private function migrateProducts(): void
    {
        Producto::query()
            ->orderBy('id')
            ->get()
            ->each(function (Producto $product): void {
                $categoryId = $product->categoria_producto_id
                    ? Category::query()
                        ->where('legacy_table', 'categoria_productos')
                        ->where('legacy_id', $product->categoria_producto_id)
                        ->value('id')
                    : null;

                $recipeRows = DB::table('producto_insumos')
                    ->where('producto_id', $product->id)
                    ->get();

                $simpleInventoryItemId = null;
                $productType = $recipeRows->isEmpty() ? 'prepared' : 'prepared';

                if ($recipeRows->count() === 1 && round((float) $recipeRows->first()->cantidad_requerida, 3) === 1.0) {
                    $simpleInventoryItemId = DB::table('insumos')
                        ->where('id', $recipeRows->first()->insumo_id)
                        ->value('inventory_item_id');

                    $productType = $simpleInventoryItemId ? 'simple' : 'prepared';
                }

                $product->update([
                    'category_id' => $categoryId,
                    'inventory_item_id' => $simpleInventoryItemId,
                    'product_type' => $productType,
                ]);

                if ($simpleInventoryItemId) {
                    InventoryItem::query()
                        ->whereKey($simpleInventoryItemId)
                        ->update(['is_sellable' => true]);
                }
            });
    }

    private function migrateRecipes(): void
    {
        DB::table('producto_insumos')
            ->orderBy('id')
            ->get()
            ->each(function (object $legacyRecipe): void {
                $inventoryItemId = DB::table('insumos')
                    ->where('id', $legacyRecipe->insumo_id)
                    ->value('inventory_item_id');

                if (! $inventoryItemId) {
                    return;
                }

                ProductRecipe::query()->updateOrCreate(
                    ['legacy_producto_insumo_id' => $legacyRecipe->id],
                    [
                        'product_id' => $legacyRecipe->producto_id,
                        'inventory_item_id' => $inventoryItemId,
                        'quantity' => (float) $legacyRecipe->cantidad_requerida,
                    ],
                );
            });
    }

    private function migrateLegacyMovements(): void
    {
        DB::table('movimiento_inventarios')
            ->orderBy('id')
            ->get()
            ->each(function (object $legacyMovement): void {
                $inventoryItemId = DB::table('insumos')
                    ->where('id', $legacyMovement->insumo_id)
                    ->value('inventory_item_id');

                if (! $inventoryItemId) {
                    return;
                }

                InventoryMovement::query()->updateOrCreate(
                    ['legacy_movimiento_inventario_id' => $legacyMovement->id],
                    [
                        'inventory_item_id' => $inventoryItemId,
                        'user_id' => $legacyMovement->user_id,
                        'movement_type' => $this->mapMovementType((string) $legacyMovement->tipo, (float) $legacyMovement->costo_unitario),
                        'quantity' => (float) $legacyMovement->cantidad,
                        'unit_cost' => (float) $legacyMovement->costo_unitario,
                        'average_cost_after' => null,
                        'stock_after' => null,
                        'reference_type' => $legacyMovement->referencia_tipo,
                        'reference_id' => $legacyMovement->referencia_id,
                        'notes' => $legacyMovement->motivo,
                        'created_at' => $legacyMovement->fecha_movimiento ?? $legacyMovement->created_at,
                        'updated_at' => $legacyMovement->updated_at,
                    ],
                );
            });
    }

    private function unitForLegacyName(string $legacyUnit): Unit
    {
        $unitName = trim(mb_strtolower($legacyUnit));

        $mappedName = match ($unitName) {
            'pz', 'pieza', 'piezas' => 'pieza',
            'l', 'lt', 'litro', 'litros' => 'litro',
            'ml', 'mililitro', 'mililitros' => 'mililitro',
            'kg', 'kilo', 'kilogramo', 'kilogramos' => 'kilogramo',
            'g', 'gr', 'gramo', 'gramos' => 'gramo',
            'bola', 'bolas' => 'bola',
            'bolsa', 'bolsas' => 'bolsa',
            'caja', 'cajas' => 'caja',
            'paquete', 'paquetes' => 'paquete',
            default => 'pieza',
        };

        return Unit::query()->where('name', $mappedName)->firstOrFail();
    }

    private function mapMovementType(string $legacyType, float $unitCost): string
    {
        return match ($legacyType) {
            'entrada' => $unitCost > 0 ? 'purchase' : 'manual_in',
            'salida' => 'manual_out',
            'venta' => 'sale',
            'merma' => 'waste',
            'devolucion' => 'return',
            default => 'adjustment',
        };
    }
}

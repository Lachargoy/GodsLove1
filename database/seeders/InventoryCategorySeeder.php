<?php

namespace Database\Seeders;

use App\Models\Category;
use Illuminate\Database\Seeder;

class InventoryCategorySeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $categories = [
            ['name' => 'General', 'type' => 'general', 'is_active' => true],
            ['name' => 'Productos', 'type' => 'product', 'is_active' => true],
            ['name' => 'Inventario', 'type' => 'inventory_item', 'is_active' => true],
            ['name' => 'Gastos', 'type' => 'expense', 'is_active' => true],
            ['name' => 'Activos', 'type' => 'asset', 'is_active' => true],
        ];

        foreach ($categories as $category) {
            Category::query()->updateOrCreate(
                [
                    'type' => $category['type'],
                    'name' => $category['name'],
                ],
                $category,
            );
        }
    }
}

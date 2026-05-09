<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        $this->call([
            UnitSeeder::class,
            InventoryCategorySeeder::class,
            CategoriaProductoSeeder::class,
            CategoriaInsumoSeeder::class,
            CategoriaGastoSeeder::class,
            ProductoSeeder::class,
            InsumoSeeder::class,
            ProductoInsumoSeeder::class,
        ]);
    }
}

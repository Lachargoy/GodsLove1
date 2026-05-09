<?php

namespace Database\Seeders;

use App\Models\Unit;
use Illuminate\Database\Seeder;

class UnitSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $units = [
            ['name' => 'pieza', 'abbreviation' => 'pz', 'allows_decimals' => false],
            ['name' => 'litro', 'abbreviation' => 'L', 'allows_decimals' => true],
            ['name' => 'mililitro', 'abbreviation' => 'ml', 'allows_decimals' => true],
            ['name' => 'kilogramo', 'abbreviation' => 'kg', 'allows_decimals' => true],
            ['name' => 'gramo', 'abbreviation' => 'g', 'allows_decimals' => true],
            ['name' => 'paquete', 'abbreviation' => 'paquete', 'allows_decimals' => false],
            ['name' => 'caja', 'abbreviation' => 'caja', 'allows_decimals' => false],
            ['name' => 'bolsa', 'abbreviation' => 'bolsa', 'allows_decimals' => false],
            ['name' => 'bola', 'abbreviation' => 'bola', 'allows_decimals' => false],
        ];

        foreach ($units as $unit) {
            Unit::query()->updateOrCreate(
                ['name' => $unit['name']],
                $unit,
            );
        }
    }
}

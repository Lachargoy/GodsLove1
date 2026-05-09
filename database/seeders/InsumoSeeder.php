<?php

namespace Database\Seeders;

use App\Models\CategoriaInsumo;
use App\Models\Insumo;
use Illuminate\Database\Seeder;

class InsumoSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $desechables = CategoriaInsumo::query()->where('nombre', 'Desechables')->firstOrFail();
        $lacteos = CategoriaInsumo::query()->where('nombre', 'Lácteos')->firstOrFail();
        $toppings = CategoriaInsumo::query()->where('nombre', 'Toppings dulces')->first()
            ?? CategoriaInsumo::query()->where('nombre', 'Toppings')->first()
            ?? $lacteos;

        $insumos = [
            [
                'categoria_insumo_id' => $desechables->id,
                'nombre' => 'Conos',
                'unidad_medida' => 'pieza',
                'cantidad_actual' => 100,
                'cantidad_minima' => 30,
                'costo_unitario' => 1.50,
                'activo' => true,
            ],
            [
                'categoria_insumo_id' => $desechables->id,
                'nombre' => 'Servilletas',
                'unidad_medida' => 'pieza',
                'cantidad_actual' => 200,
                'cantidad_minima' => 50,
                'costo_unitario' => 0.20,
                'activo' => true,
            ],
            [
                'categoria_insumo_id' => $lacteos->id,
                'nombre' => 'Helado de vainilla',
                'unidad_medida' => 'litro',
                'cantidad_actual' => 10,
                'cantidad_minima' => 3,
                'costo_unitario' => 85,
                'activo' => true,
            ],
            [
                'categoria_insumo_id' => $lacteos->id,
                'nombre' => 'Leche',
                'unidad_medida' => 'litro',
                'cantidad_actual' => 8,
                'cantidad_minima' => 2,
                'costo_unitario' => 28,
                'activo' => true,
            ],
            [
                'categoria_insumo_id' => $toppings->id,
                'nombre' => 'Chocolate líquido',
                'unidad_medida' => 'litro',
                'cantidad_actual' => 3,
                'cantidad_minima' => 1,
                'costo_unitario' => 95,
                'activo' => true,
            ],
        ];

        foreach ($insumos as $insumo) {
            Insumo::query()->updateOrCreate(
                ['nombre' => $insumo['nombre']],
                $insumo,
            );
        }
    }
}

<?php

namespace Database\Seeders;

use App\Models\Insumo;
use App\Models\Producto;
use App\Models\ProductoInsumo;
use Illuminate\Database\Seeder;

class ProductoInsumoSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $recetas = [
            'Cono sencillo' => [
                'Conos' => 1,
                'Helado de vainilla' => 0.120,
                'Servilletas' => 1,
            ],
            'Cono doble' => [
                'Conos' => 1,
                'Helado de vainilla' => 0.240,
                'Servilletas' => 1,
            ],
            'Malteada' => [
                'Helado de vainilla' => 0.200,
                'Leche' => 0.300,
                'Chocolate líquido' => 0.030,
                'Servilletas' => 1,
            ],
        ];

        foreach ($recetas as $productoNombre => $insumos) {
            $producto = Producto::query()->where('nombre', $productoNombre)->firstOrFail();

            foreach ($insumos as $insumoNombre => $cantidadRequerida) {
                $insumo = Insumo::query()->where('nombre', $insumoNombre)->firstOrFail();

                ProductoInsumo::query()->updateOrCreate(
                    [
                        'producto_id' => $producto->id,
                        'insumo_id' => $insumo->id,
                    ],
                    [
                        'cantidad_requerida' => $cantidadRequerida,
                    ],
                );
            }
        }
    }
}

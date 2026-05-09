<?php

namespace Database\Seeders;

use App\Models\CategoriaInsumo;
use Illuminate\Database\Seeder;

class CategoriaInsumoSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $categorias = [
            [
                'nombre' => 'Lácteos',
                'descripcion' => 'Leche, crema, bases lácteas, helado base y derivados.',
                'activo' => true,
            ],
            [
                'nombre' => 'Frutas',
                'descripcion' => 'Frutas frescas, congeladas, pulpas y preparados naturales.',
                'activo' => true,
            ],
            [
                'nombre' => 'Saborizantes y jarabes',
                'descripcion' => 'Jarabes, esencias, concentrados, saborizantes y colorantes.',
                'activo' => true,
            ],
            [
                'nombre' => 'Toppings dulces',
                'descripcion' => 'Chispas, granola, galleta, chocolate líquido, cajeta, cereales y extras dulces.',
                'activo' => true,
            ],
            [
                'nombre' => 'Toppings salados',
                'descripcion' => 'Queso, salsas, chamoy, chile, cacahuates, cueritos y extras para snacks.',
                'activo' => true,
            ],
            [
                'nombre' => 'Botanas',
                'descripcion' => 'Tostitos, nachos, papas, chicharrones, palomitas, frituras y bases de snacks.',
                'activo' => true,
            ],
            [
                'nombre' => 'Dulces y gomitas',
                'descripcion' => 'Gomitas, dulces enchilados, caramelos, chocolates y dulcería a granel.',
                'activo' => true,
            ],
            [
                'nombre' => 'Bebidas e ingredientes líquidos',
                'descripcion' => 'Agua, jugos, refrescos, concentrados, leche para malteadas y líquidos de preparación.',
                'activo' => true,
            ],
            [
                'nombre' => 'Desechables',
                'descripcion' => 'Conos, vasos, tapas, cucharas, popotes, servilletas, platos y bolsas.',
                'activo' => true,
            ],
            [
                'nombre' => 'Empaques',
                'descripcion' => 'Envases, bolsas, etiquetas, cajas, recipientes y empaques para entrega.',
                'activo' => true,
            ],
            [
                'nombre' => 'Limpieza',
                'descripcion' => 'Jabón, cloro, desinfectante, trapos, guantes, bolsas de basura y limpieza general.',
                'activo' => true,
            ],
            [
                'nombre' => 'Operación',
                'descripcion' => 'Materiales de uso interno: gas, hielo, utensilios menores y consumibles operativos.',
                'activo' => true,
            ],
            [
                'nombre' => 'Otros',
                'descripcion' => 'Insumos no clasificados todavía.',
                'activo' => true,
            ],
            [
                'nombre' => 'paletas de agua',
                'descripcion' => 'Paletas de agua congeladas.',
                'activo' => true,
            ],
            [
                'nombre' => 'paletas de leche',
                'descripcion' => 'Paletas de leche',
                'activo' => true,
            ],
            [
                'nombre' => 'chamoyada helada',
                'descripcion' => 'producto con chamoy congelado',
                  'activo' => true,
            ],
        ];

        foreach ($categorias as $categoria) {
            CategoriaInsumo::query()->updateOrCreate(
                ['nombre' => $categoria['nombre']],
                $categoria,
            );
        }
    }
}
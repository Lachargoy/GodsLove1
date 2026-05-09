<?php

namespace Database\Seeders;

use App\Models\CategoriaProducto;
use Illuminate\Database\Seeder;

class CategoriaProductoSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $categorias = [
            [
                'nombre' => 'Helados',
                'descripcion' => 'Conos, copas, litros, bolas de helado y presentaciones principales.',
                'activo' => true,
            ],
            [
                'nombre' => 'Nieves',
                'descripcion' => 'Nieves de agua, fruta, crema y sabores especiales.',
                'activo' => true,
            ],
            [
                'nombre' => 'Bolis',
                'descripcion' => 'Bolis de agua, leche, fruta, crema y sabores preparados.',
                'activo' => true,
            ],
            [
                'nombre' => 'Paletas',
                'descripcion' => 'Paletas de agua, leche, crema, fruta o especiales.',
                'activo' => true,
            ],
            [
                'nombre' => 'Chamoyadas',
                'descripcion' => 'Chamoyadas, mangonadas, raspados con chamoy y preparados congelados.',
                'activo' => true,
            ],
            [
                'nombre' => 'Aguas frescas',
                'descripcion' => 'Aguas naturales, aguas de sabor, preparados frios y bebidas por vaso o litro.',
                'activo' => true,
            ],
            [
                'nombre' => 'Bebidas embotelladas',
                'descripcion' => 'Refrescos, agua embotellada, jugos, tes y bebidas listas para vender.',
                'activo' => true,
            ],
            [
                'nombre' => 'Malteadas',
                'descripcion' => 'Malteadas preparadas con helado, leche, jarabes y toppings.',
                'activo' => true,
            ],
            [
                'nombre' => 'Postres',
                'descripcion' => 'Pasteles, flanes, gelatinas, brownies, pays y postres frios.',
                'activo' => true,
            ],
            [
                'nombre' => 'Papas',
                'descripcion' => 'Papas fritas, papas preparadas, papas con toppings y botanas de bolsa.',
                'activo' => true,
            ],
            [
                'nombre' => 'Preparados',
                'descripcion' => 'Tostitos, dorilocos, chicharrones preparados, nachos y botanas preparadas.',
                'activo' => true,
            ],
            [
                'nombre' => 'Gomitas',
                'descripcion' => 'Gomitas naturales, enchiladas, dulces a granel y presentaciones preparadas.',
                'activo' => true,
            ],
            [
                'nombre' => 'Palomitas',
                'descripcion' => 'Palomitas naturales, mantequilla, caramelo, queso, chile y sabores especiales.',
                'activo' => true,
            ],
            [
                'nombre' => 'Toppings',
                'descripcion' => 'Extras para helados, postres, aguas, snacks y productos preparados.',
                'activo' => true,
            ],
            [
                'nombre' => 'Combos',
                'descripcion' => 'Paquetes de productos combinados para venta rapida.',
                'activo' => true,
            ],
            [
                'nombre' => 'Promociones internas',
                'descripcion' => 'Productos temporales, ofertas del dia o paquetes promocionales.',
                'activo' => true,
            ],
            [
                'nombre' => 'Otros',
                'descripcion' => 'Productos que no entren todavia en una categoria especifica.',
                'activo' => true,
            ],
        ];

        foreach ($categorias as $categoria) {
            CategoriaProducto::query()->updateOrCreate(
                ['nombre' => $categoria['nombre']],
                $categoria,
            );
        }
    }
}

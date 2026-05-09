<?php

namespace Database\Seeders;

use App\Models\CategoriaProducto;
use App\Models\Producto;
use Illuminate\Database\Seeder;

class ProductoSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $helados = CategoriaProducto::query()->where('nombre', 'Helados')->firstOrFail();
        $bebidas = CategoriaProducto::query()->where('nombre', 'Malteadas')->first()
            ?? CategoriaProducto::query()->where('nombre', 'Bebidas')->first()
            ?? $helados;

        $productos = [
            [
                'categoria_producto_id' => $helados->id,
                'nombre' => 'Cono sencillo',
                'descripcion' => 'Cono con una bola de helado',
                'precio_venta' => 30,
                'costo_estimado' => 12,
                'activo' => true,
            ],
            [
                'categoria_producto_id' => $helados->id,
                'nombre' => 'Cono doble',
                'descripcion' => 'Cono con dos bolas de helado',
                'precio_venta' => 45,
                'costo_estimado' => 18,
                'activo' => true,
            ],
            [
                'categoria_producto_id' => $bebidas->id,
                'nombre' => 'Malteada',
                'descripcion' => 'Malteada preparada',
                'precio_venta' => 60,
                'costo_estimado' => 25,
                'activo' => true,
            ],
        ];

        foreach ($productos as $producto) {
            Producto::query()->updateOrCreate(
                ['nombre' => $producto['nombre']],
                $producto,
            );
        }
    }
}

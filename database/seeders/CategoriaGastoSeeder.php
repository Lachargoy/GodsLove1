<?php

namespace Database\Seeders;

use App\Models\CategoriaGasto;
use Illuminate\Database\Seeder;

class CategoriaGastoSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $categorias = [
            [
                'nombre' => 'Renta',
                'descripcion' => 'Pago del local o espacio de venta.',
                'activo' => true,
            ],
            [
                'nombre' => 'Luz',
                'descripcion' => 'Pago de electricidad.',
                'activo' => true,
            ],
            [
                'nombre' => 'Agua',
                'descripcion' => 'Pago de agua potable.',
                'activo' => true,
            ],
            [
                'nombre' => 'Internet y teléfono',
                'descripcion' => 'Servicios de internet, telefonía y comunicación.',
                'activo' => true,
            ],
            [
                'nombre' => 'Sueldos',
                'descripcion' => 'Pago de personal, ayudantes o turnos.',
                'activo' => true,
            ],
            [
                'nombre' => 'Materia prima',
                'descripcion' => 'Compra de insumos para preparar o vender productos.',
                'activo' => true,
            ],
            [
                'nombre' => 'Desechables y empaques',
                'descripcion' => 'Compra de vasos, cucharas, bolsas, envases y empaques.',
                'activo' => true,
            ],
            [
                'nombre' => 'Mantenimiento',
                'descripcion' => 'Reparaciones, servicio de congeladores, refrigeradores, vitrinas y equipo.',
                'activo' => true,
            ],
            [
                'nombre' => 'Equipo y utensilios',
                'descripcion' => 'Compra de herramientas, utensilios, equipo menor o mobiliario.',
                'activo' => true,
            ],
            [
                'nombre' => 'Publicidad',
                'descripcion' => 'Anuncios, redes sociales, lonas, volantes, promociones y marketing.',
                'activo' => true,
            ],
            [
                'nombre' => 'Transporte',
                'descripcion' => 'Gasolina, fletes, envíos, mandados y traslados.',
                'activo' => true,
            ],
            [
                'nombre' => 'Comisiones bancarias',
                'descripcion' => 'Comisiones por tarjeta, transferencias, terminal bancaria o plataformas.',
                'activo' => true,
            ],
            [
                'nombre' => 'Permisos e impuestos',
                'descripcion' => 'Pagos administrativos, permisos, licencias o impuestos.',
                'activo' => true,
            ],
            [
                'nombre' => 'Limpieza',
                'descripcion' => 'Productos y servicios de limpieza.',
                'activo' => true,
            ],
            [
                'nombre' => 'Merma o pérdida',
                'descripcion' => 'Pérdidas, productos dañados o diferencias no recuperables.',
                'activo' => true,
            ],
            [
                'nombre' => 'Otros',
                'descripcion' => 'Gastos varios no clasificados.',
                'activo' => true,
            ],
        ];

        foreach ($categorias as $categoria) {
            CategoriaGasto::query()->updateOrCreate(
                ['nombre' => $categoria['nombre']],
                $categoria,
            );
        }
    }
}
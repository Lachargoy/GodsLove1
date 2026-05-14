<?php

use Illuminate\Support\Facades\Route;
use Livewire\Volt\Volt;

Route::view('/', 'welcome')->name('home');

Route::middleware(['auth', 'verified'])->group(function () {
    Route::view('dashboard', 'dashboard')->name('dashboard');

    Volt::route('/productos', 'productos.index')
        ->name('productos.index');

    Volt::route('/categorias', 'categorias.index')
        ->name('categorias.index');

    Volt::route('/productos/recetas', 'productos.recetas')
        ->name('productos.recetas');

    Volt::route('/insumos', 'insumos.index')
        ->name('insumos.index');

    Volt::route('/inventario/movimientos', 'inventario.movimientos')
        ->name('inventario.movimientos');

    Volt::route('/ventas/nueva', 'ventas.punto-venta')
        ->name('ventas.punto');

    Volt::route('/caja/corte', 'caja.corte')
        ->name('caja.corte');

    Volt::route('/finanzas/cierres', 'finanzas.cierres')
        ->name('finanzas.cierres');

    Volt::route('/gastos', 'gastos.index')
        ->name('gastos.index');

    Volt::route('/asistente', 'asistente.index')
        ->name('asistente.index');
});

require __DIR__.'/settings.php';

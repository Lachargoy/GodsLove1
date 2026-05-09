<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
    Schema::create('insumos', function (Blueprint $table) {
        $table->id();

        $table->foreignId('categoria_insumo_id')
            ->nullable()
            ->constrained('categoria_insumos')
            ->nullOnDelete();

        $table->string('nombre');
        $table->string('unidad_medida')->default('pieza');

        $table->decimal('cantidad_actual', 12, 3)->default(0);
        $table->decimal('cantidad_minima', 12, 3)->default(0);
        $table->decimal('costo_unitario', 10, 2)->default(0);

        $table->boolean('activo')->default(true);

        $table->timestamps();
    });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('insumos');
    }
};

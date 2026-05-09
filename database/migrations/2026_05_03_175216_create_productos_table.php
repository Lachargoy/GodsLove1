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
    Schema::create('productos', function (Blueprint $table) {
        $table->id();

        $table->foreignId('categoria_producto_id')
            ->nullable()
            ->constrained('categoria_productos')
            ->nullOnDelete();

        $table->string('nombre');
        $table->text('descripcion')->nullable();

        $table->decimal('precio_venta', 10, 2);
        $table->decimal('costo_estimado', 10, 2)->default(0);

        $table->boolean('activo')->default(true);

        $table->timestamps();
    });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('productos');
    }
};

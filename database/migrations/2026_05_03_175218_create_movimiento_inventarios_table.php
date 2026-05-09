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
    Schema::create('movimiento_inventarios', function (Blueprint $table) {
        $table->id();

        $table->foreignId('insumo_id')
            ->constrained('insumos')
            ->cascadeOnDelete();

        $table->foreignId('user_id')
            ->nullable()
            ->constrained()
            ->nullOnDelete();

        $table->string('tipo');
        $table->decimal('cantidad', 12, 3);
        $table->decimal('costo_unitario', 10, 2)->default(0);

        $table->string('referencia_tipo')->nullable();
        $table->unsignedBigInteger('referencia_id')->nullable();

        $table->text('motivo')->nullable();
        $table->timestamp('fecha_movimiento')->nullable();

        $table->timestamps();
    });
}

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('movimiento_inventarios');
    }
};

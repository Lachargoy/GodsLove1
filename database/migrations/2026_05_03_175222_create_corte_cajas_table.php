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
    Schema::create('corte_cajas', function (Blueprint $table) {
        $table->id();

        $table->foreignId('user_id')
            ->nullable()
            ->constrained()
            ->nullOnDelete();

        $table->timestamp('fecha_apertura')->nullable();
        $table->timestamp('fecha_cierre')->nullable();

        $table->decimal('monto_inicial', 10, 2)->default(0);

        $table->decimal('ventas_efectivo', 10, 2)->default(0);
        $table->decimal('ventas_tarjeta', 10, 2)->default(0);
        $table->decimal('ventas_transferencia', 10, 2)->default(0);

        $table->decimal('gastos_turno', 10, 2)->default(0);

        $table->decimal('monto_esperado', 10, 2)->default(0);
        $table->decimal('monto_real', 10, 2)->default(0);
        $table->decimal('diferencia', 10, 2)->default(0);

        $table->string('estado')->default('abierto');
        $table->text('observaciones')->nullable();

        $table->timestamps();
    });
}

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('corte_cajas');
    }
};

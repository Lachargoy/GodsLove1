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
    Schema::create('gastos', function (Blueprint $table) {
        $table->id();

        $table->foreignId('categoria_gasto_id')
            ->nullable()
            ->constrained('categoria_gastos')
            ->nullOnDelete();

        $table->foreignId('user_id')
            ->nullable()
            ->constrained()
            ->nullOnDelete();

        $table->string('descripcion');
        $table->decimal('monto', 10, 2);

        $table->string('tipo')->default('variable');
        $table->string('metodo_pago')->default('efectivo');

        $table->date('fecha_gasto');
        $table->string('comprobante')->nullable();

        $table->timestamps();
    });
}

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('gastos');
    }
};

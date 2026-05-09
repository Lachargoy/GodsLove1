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
    Schema::create('ventas', function (Blueprint $table) {
        $table->id();

        $table->foreignId('user_id')
            ->nullable()
            ->constrained()
            ->nullOnDelete();

        $table->string('folio')->unique();

        $table->decimal('subtotal', 10, 2)->default(0);
        $table->decimal('descuento', 10, 2)->default(0);
        $table->decimal('total', 10, 2)->default(0);

        $table->string('metodo_pago')->default('efectivo');
        $table->string('estado')->default('pagada');

        $table->timestamp('fecha_venta')->nullable();

        $table->timestamps();
    });
}
    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('ventas');
    }
};

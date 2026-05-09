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
        Schema::table('ventas', function (Blueprint $table) {
            $table->foreignId('corte_caja_id')
                ->nullable()
                ->after('user_id')
                ->constrained('corte_cajas')
                ->nullOnDelete();
        });

        Schema::table('gastos', function (Blueprint $table) {
            $table->foreignId('corte_caja_id')
                ->nullable()
                ->after('user_id')
                ->constrained('corte_cajas')
                ->nullOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('ventas', function (Blueprint $table) {
            $table->dropConstrainedForeignId('corte_caja_id');
        });

        Schema::table('gastos', function (Blueprint $table) {
            $table->dropConstrainedForeignId('corte_caja_id');
        });
    }
};

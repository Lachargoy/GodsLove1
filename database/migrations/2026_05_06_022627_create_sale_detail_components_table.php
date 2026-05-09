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
        Schema::create('sale_detail_components', function (Blueprint $table) {
            $table->id();
            $table->foreignId('sale_detail_id')
                ->constrained('venta_detalles')
                ->cascadeOnDelete();
            $table->foreignId('inventory_item_id')
                ->constrained('inventory_items')
                ->restrictOnDelete();
            $table->decimal('quantity_consumed', 14, 3);
            $table->decimal('unit_cost_at_sale', 12, 4);
            $table->decimal('total_cost', 12, 4);
            $table->timestamps();

            $table->index(['sale_detail_id', 'inventory_item_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('sale_detail_components');
    }
};

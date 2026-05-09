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
        Schema::table('productos', function (Blueprint $table) {
            $table->string('product_type')
                ->default('prepared')
                ->after('costo_estimado');
            $table->foreignId('inventory_item_id')
                ->nullable()
                ->after('product_type')
                ->constrained('inventory_items')
                ->nullOnDelete();
            $table->foreignId('category_id')
                ->nullable()
                ->after('inventory_item_id')
                ->constrained('categories')
                ->nullOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('productos', function (Blueprint $table) {
            $table->dropConstrainedForeignId('category_id');
            $table->dropConstrainedForeignId('inventory_item_id');
            $table->dropColumn('product_type');
        });
    }
};

<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SaleDetailComponent extends Model
{
    use HasFactory;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'sale_detail_id',
        'inventory_item_id',
        'quantity_consumed',
        'unit_cost_at_sale',
        'total_cost',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'quantity_consumed' => 'decimal:3',
            'unit_cost_at_sale' => 'decimal:4',
            'total_cost' => 'decimal:4',
        ];
    }

    public function saleDetail(): BelongsTo
    {
        return $this->belongsTo(VentaDetalle::class, 'sale_detail_id');
    }

    public function inventoryItem(): BelongsTo
    {
        return $this->belongsTo(InventoryItem::class);
    }
}

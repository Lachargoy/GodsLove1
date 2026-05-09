<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Producto extends Model
{
    use HasFactory;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'categoria_producto_id',
        'inventory_item_id',
        'category_id',
        'nombre',
        'descripcion',
        'precio_venta',
        'costo_estimado',
        'product_type',
        'activo',
    ];

    public function categoria(): BelongsTo
    {
        return $this->belongsTo(CategoriaProducto::class, 'categoria_producto_id');
    }

    public function ventaDetalles(): HasMany
    {
        return $this->hasMany(VentaDetalle::class, 'producto_id');
    }

    public function insumos(): BelongsToMany
    {
        return $this->belongsToMany(Insumo::class, 'producto_insumos')
            ->withPivot('cantidad_requerida')
            ->withTimestamps();
    }

    public function inventoryItem(): BelongsTo
    {
        return $this->belongsTo(InventoryItem::class);
    }

    public function unifiedCategory(): BelongsTo
    {
        return $this->belongsTo(Category::class, 'category_id');
    }

    public function productRecipes(): HasMany
    {
        return $this->hasMany(ProductRecipe::class, 'product_id');
    }

    public function productOptionGroups(): HasMany
    {
        return $this->hasMany(ProductOptionGroup::class, 'product_id');
    }
}

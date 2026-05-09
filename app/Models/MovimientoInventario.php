<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MovimientoInventario extends Model
{
    use HasFactory;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'insumo_id',
        'user_id',
        'tipo',
        'cantidad',
        'costo_unitario',
        'referencia_tipo',
        'referencia_id',
        'motivo',
        'fecha_movimiento',
    ];

    public function insumo(): BelongsTo
    {
        return $this->belongsTo(Insumo::class, 'insumo_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }
}

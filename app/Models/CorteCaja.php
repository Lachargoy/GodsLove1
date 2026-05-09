<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class CorteCaja extends Model
{
    use HasFactory;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'user_id',
        'fecha_apertura',
        'fecha_cierre',
        'monto_inicial',
        'ventas_efectivo',
        'ventas_tarjeta',
        'ventas_transferencia',
        'gastos_turno',
        'monto_esperado',
        'monto_real',
        'diferencia',
        'estado',
        'observaciones',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function ventas(): HasMany
    {
        return $this->hasMany(Venta::class, 'corte_caja_id');
    }

    public function gastos(): HasMany
    {
        return $this->hasMany(Gasto::class, 'corte_caja_id');
    }

    public function scopeAbiertaDelDia(Builder $query): Builder
    {
        return $query
            ->where('estado', 'abierto')
            ->whereDate('fecha_apertura', today());
    }
}

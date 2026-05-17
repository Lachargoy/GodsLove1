<?php

use App\Models\CategoriaGasto;
use App\Models\CorteCaja;
use App\Models\Gasto;
use Livewire\Component;

new class extends Component
{
    public string $search = '';
    public string $categoria_gasto_id = '';
    public string $descripcion = '';
    public string $monto = '';
    public string $tipo = 'variable';
    public string $metodo_pago = 'efectivo';
    public string $origen = 'caja_dia';
    public string $origen_filter = '';
    public string $fecha_gasto = '';

    public function mount(): void
    {
        $this->fecha_gasto = now()->toDateString();
    }

    public function getCategoriasProperty()
    {
        return CategoriaGasto::query()
            ->where('activo', true)
            ->orderBy('nombre')
            ->get();
    }

    public function getCajaAbiertaProperty(): ?CorteCaja
    {
        return CorteCaja::query()
            ->abiertaDelDia()
            ->latest('fecha_apertura')
            ->first();
    }

    public function getGastosProperty()
    {
        return Gasto::query()
            ->with(['categoria', 'user'])
            ->when($this->search !== '', function ($query) {
                $query->where('descripcion', 'like', '%'.trim($this->search).'%');
            })
            ->when($this->categoria_gasto_id !== '', function ($query) {
                $query->where('categoria_gasto_id', $this->categoria_gasto_id);
            })
            ->when($this->origen_filter !== '', function ($query) {
                $query->where('origen', $this->origen_filter);
            })
            ->orderByDesc('fecha_gasto')
            ->orderByDesc('created_at')
            ->get();
    }

    public function getTotalGastosProperty(): int
    {
        return Gasto::query()->count();
    }

    public function getGastosHoyProperty(): float
    {
        return round(
            (float) Gasto::query()
                ->whereDate('fecha_gasto', today())
                ->where('origen', '!=', 'inversion_extra')
                ->sum('monto'),
            2,
        );
    }

    public function getGastosMesProperty(): float
    {
        return round(
            (float) Gasto::query()
                ->whereYear('fecha_gasto', now()->year)
                ->whereMonth('fecha_gasto', now()->month)
                ->where('origen', '!=', 'inversion_extra')
                ->sum('monto'),
            2,
        );
    }

    public function getGastosCajaDiaHoyProperty(): float
    {
        return $this->sumarGastosPorOrigen('caja_dia', today()->toDateString(), today()->toDateString());
    }

    public function getGastosBalanceGeneralHoyProperty(): float
    {
        return $this->sumarGastosPorOrigen('balance_general', today()->toDateString(), today()->toDateString());
    }

    public function getInversionExtraMesProperty(): float
    {
        return round(
            (float) Gasto::query()
                ->whereYear('fecha_gasto', now()->year)
                ->whereMonth('fecha_gasto', now()->month)
                ->where('origen', 'inversion_extra')
                ->sum('monto'),
            2,
        );
    }

    public function getPromedioGastoProperty(): float
    {
        $gastosMesCount = Gasto::query()
            ->whereYear('fecha_gasto', now()->year)
            ->whereMonth('fecha_gasto', now()->month)
            ->where('origen', '!=', 'inversion_extra')
            ->count();

        if ($gastosMesCount === 0) {
            return 0;
        }

        return round($this->gastosMes / $gastosMesCount, 2);
    }

    public function guardar(): void
    {
        $validated = $this->validate([
            'categoria_gasto_id' => ['nullable', 'exists:categoria_gastos,id'],
            'descripcion' => ['required', 'string', 'max:255'],
            'monto' => ['required', 'numeric', 'min:0'],
            'tipo' => ['required', 'in:fijo,variable'],
            'metodo_pago' => ['required', 'in:efectivo,tarjeta,transferencia,mixto'],
            'origen' => ['required', 'in:caja_dia,balance_general,inversion_extra'],
            'fecha_gasto' => ['required', 'date'],
        ]);

        if ($validated['origen'] === 'caja_dia' && ! $this->cajaAbierta instanceof CorteCaja) {
            $this->addError('origen', 'No hay caja del dia abierta. Elige balance general o abre caja.');

            return;
        }

        if ($validated['origen'] === 'caja_dia' && $validated['metodo_pago'] !== 'efectivo') {
            $this->addError('metodo_pago', 'Un gasto de caja del dia debe salir en efectivo para que el corte cuadre.');

            return;
        }

        Gasto::query()->create([
            'categoria_gasto_id' => $validated['categoria_gasto_id'] ?: null,
            'user_id' => auth()->id(),
            'corte_caja_id' => $validated['origen'] === 'caja_dia' ? $this->cajaAbierta?->id : null,
            'descripcion' => trim($validated['descripcion']),
            'monto' => round((float) $validated['monto'], 2),
            'tipo' => $validated['tipo'],
            'metodo_pago' => $validated['metodo_pago'],
            'origen' => $validated['origen'],
            'fecha_gasto' => $validated['fecha_gasto'],
        ]);

        $this->reset([
            'categoria_gasto_id',
            'descripcion',
            'monto',
        ]);

        $this->tipo = 'variable';
        $this->metodo_pago = 'efectivo';
        $this->origen = 'caja_dia';
        $this->fecha_gasto = now()->toDateString();

        session()->flash('success', 'Gasto registrado correctamente.');
    }

    public function limpiarFiltros(): void
    {
        $this->reset([
            'search',
            'categoria_gasto_id',
            'origen_filter',
        ]);
    }

    private function sumarGastosPorOrigen(string $origen, string $desde, string $hasta): float
    {
        return round(
            (float) Gasto::query()
                ->where('origen', $origen)
                ->whereBetween('fecha_gasto', [$desde, $hasta])
                ->sum('monto'),
            2,
        );
    }
};
?>

<div class="space-y-6">
    <div class="flex flex-col gap-2 sm:flex-row sm:items-end sm:justify-between">
        <div>
            <h1 class="text-2xl font-bold text-gray-900">Gastos</h1>
            <p class="text-sm text-gray-500">
                Registra egresos del negocio y mantenlos listos para el corte del turno.
            </p>
        </div>

        <a href="{{ route('categorias.index') }}" wire:navigate class="inline-flex items-center rounded-xl border border-slate-300 px-4 py-2 text-sm font-medium text-slate-700 transition hover:bg-slate-50">
            Administrar categorias
        </a>
    </div>

    <div class="grid gap-4 md:grid-cols-2 xl:grid-cols-4">
        <div class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm">
            <p class="text-sm text-slate-500">Total registros</p>
            <p class="mt-2 text-3xl font-semibold text-slate-900">{{ $this->totalGastos }}</p>
        </div>

        <div class="rounded-2xl border border-emerald-200 bg-emerald-50 p-5 shadow-sm">
            <p class="text-sm text-emerald-700">Gastos operativos hoy</p>
            <p class="mt-2 text-3xl font-semibold text-emerald-900">${{ number_format($this->gastosHoy, 2) }}</p>
            <p class="mt-1 text-xs text-emerald-700">
                Caja ${{ number_format($this->gastosCajaDiaHoy, 2) }} · Balance ${{ number_format($this->gastosBalanceGeneralHoy, 2) }}
            </p>
        </div>

        <div class="rounded-2xl border border-amber-200 bg-amber-50 p-5 shadow-sm">
            <p class="text-sm text-amber-700">Gastos operativos mes</p>
            <p class="mt-2 text-3xl font-semibold text-amber-900">${{ number_format($this->gastosMes, 2) }}</p>
        </div>

        <div class="rounded-2xl border border-sky-200 bg-sky-50 p-5 shadow-sm">
            <p class="text-sm text-sky-700">Inversion extra mes</p>
            <p class="mt-2 text-3xl font-semibold text-sky-900">${{ number_format($this->inversionExtraMes, 2) }}</p>
            <p class="mt-1 text-xs text-sky-700">Se rastrea aparte del balance operativo.</p>
        </div>
    </div>

    <div class="grid gap-6 xl:grid-cols-[1.05fr_0.95fr]">
        <div class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm">
            <div class="mb-4">
                <h2 class="text-lg font-semibold text-slate-900">Nuevo gasto</h2>
                <p class="text-sm text-slate-500">Registra compras, servicios y otros egresos del dia.</p>
            </div>

            <form wire:submit="guardar" class="grid gap-4 md:grid-cols-2">
                <div>
                    <label class="mb-1 block text-sm font-medium text-slate-700">Categoria</label>
                    <select wire:model="categoria_gasto_id" class="w-full rounded-xl border-slate-300 text-sm">
                        <option value="">Sin categoria</option>

                        @foreach ($this->categorias as $categoria)
                            <option value="{{ $categoria->id }}">{{ $categoria->nombre }}</option>
                        @endforeach
                    </select>

                    @error('categoria_gasto_id')
                        <p class="mt-1 text-sm text-red-600">{{ $message }}</p>
                    @enderror
                </div>

                <div>
                    <label class="mb-1 block text-sm font-medium text-slate-700">Monto</label>
                    <input
                        type="number"
                        step="0.01"
                        wire:model="monto"
                        class="w-full rounded-xl border-slate-300 text-sm"
                        placeholder="0.00"
                    >

                    @error('monto')
                        <p class="mt-1 text-sm text-red-600">{{ $message }}</p>
                    @enderror
                </div>

                <div class="md:col-span-2">
                    <label class="mb-1 block text-sm font-medium text-slate-700">Descripcion</label>
                    <input
                        type="text"
                        wire:model="descripcion"
                        class="w-full rounded-xl border-slate-300 text-sm"
                        placeholder="Ej. Compra de servilletas"
                    >

                    @error('descripcion')
                        <p class="mt-1 text-sm text-red-600">{{ $message }}</p>
                    @enderror
                </div>

                <div>
                    <label class="mb-1 block text-sm font-medium text-slate-700">Tipo</label>
                    <select wire:model="tipo" class="w-full rounded-xl border-slate-300 text-sm">
                        <option value="variable">variable</option>
                        <option value="fijo">fijo</option>
                    </select>

                    @error('tipo')
                        <p class="mt-1 text-sm text-red-600">{{ $message }}</p>
                    @enderror
                </div>

                <div>
                    <label class="mb-1 block text-sm font-medium text-slate-700">Metodo de pago</label>
                    <select wire:model="metodo_pago" class="w-full rounded-xl border-slate-300 text-sm">
                        <option value="efectivo">efectivo</option>
                        <option value="tarjeta">tarjeta</option>
                        <option value="transferencia">transferencia</option>
                        <option value="mixto">mixto</option>
                    </select>

                    @error('metodo_pago')
                        <p class="mt-1 text-sm text-red-600">{{ $message }}</p>
                    @enderror
                </div>

                <div class="md:col-span-2">
                    <label class="mb-1 block text-sm font-medium text-slate-700">Sale de</label>
                    <select wire:model="origen" class="w-full rounded-xl border-slate-300 text-sm">
                        <option value="caja_dia">Caja del dia</option>
                        <option value="balance_general">Balance general</option>
                        <option value="inversion_extra">Inversion extra</option>
                    </select>
                    <p class="mt-1 text-xs text-slate-500">
                        Caja del dia descuenta efectivo del corte. Balance general afecta el negocio. Inversion extra se registra aparte y no castiga el balance operativo.
                    </p>

                    @error('origen')
                        <p class="mt-1 text-sm text-red-600">{{ $message }}</p>
                    @enderror
                </div>

                <div class="md:col-span-2">
                    <label class="mb-1 block text-sm font-medium text-slate-700">Fecha del gasto</label>
                    <input
                        type="date"
                        wire:model="fecha_gasto"
                        class="w-full rounded-xl border-slate-300 text-sm"
                    >

                    @error('fecha_gasto')
                        <p class="mt-1 text-sm text-red-600">{{ $message }}</p>
                    @enderror
                </div>

                <div class="md:col-span-2">
                    <button
                        type="submit"
                        class="inline-flex items-center rounded-xl bg-slate-900 px-4 py-2 text-sm font-medium text-white transition hover:bg-slate-800"
                    >
                        Guardar gasto
                    </button>
                </div>
            </form>
        </div>

        <div class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm">
            <div class="mb-4">
                <h2 class="text-lg font-semibold text-slate-900">Filtros</h2>
                <p class="text-sm text-slate-500">Busca rapido y separa gastos por categoria.</p>
            </div>

            <div class="space-y-4">
                <div>
                    <label class="mb-1 block text-sm font-medium text-slate-700">Buscar por descripcion</label>
                    <input
                        type="text"
                        wire:model.live="search"
                        class="w-full rounded-xl border-slate-300 text-sm"
                        placeholder="Ej. Renta, servilletas, luz"
                    >
                </div>

                <div>
                    <label class="mb-1 block text-sm font-medium text-slate-700">Filtrar por origen</label>
                    <select wire:model.live="origen_filter" class="w-full rounded-xl border-slate-300 text-sm">
                        <option value="">Todos los origenes</option>
                        <option value="caja_dia">Caja del dia</option>
                        <option value="balance_general">Balance general</option>
                        <option value="inversion_extra">Inversion extra</option>
                    </select>
                </div>

                <div>
                    <label class="mb-1 block text-sm font-medium text-slate-700">Filtrar por categoria</label>
                    <select wire:model.live="categoria_gasto_id" class="w-full rounded-xl border-slate-300 text-sm">
                        <option value="">Todas las categorias</option>

                        @foreach ($this->categorias as $categoria)
                            <option value="{{ $categoria->id }}">{{ $categoria->nombre }}</option>
                        @endforeach
                    </select>
                </div>

                <button
                    type="button"
                    wire:click="limpiarFiltros"
                    class="inline-flex items-center rounded-xl border border-slate-300 px-4 py-2 text-sm font-medium text-slate-700 transition hover:bg-slate-50"
                >
                    Limpiar filtros
                </button>
            </div>
        </div>
    </div>

    <div class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm">
        <div class="mb-4">
            <h2 class="text-lg font-semibold text-slate-900">Historial de gastos</h2>
            <p class="text-sm text-slate-500">Consulta lo registrado y quien capturo cada egreso.</p>
        </div>

        <div class="overflow-x-auto">
            <table class="min-w-full border-collapse text-left text-sm">
                <thead>
                    <tr class="border-b border-slate-200 bg-slate-50 text-slate-600">
                        <th class="p-3 font-medium">Fecha</th>
                        <th class="p-3 font-medium">Descripcion</th>
                        <th class="p-3 font-medium">Categoria</th>
                        <th class="p-3 font-medium">Origen</th>
                        <th class="p-3 font-medium">Tipo</th>
                        <th class="p-3 font-medium">Metodo</th>
                        <th class="p-3 font-medium">Monto</th>
                        <th class="p-3 font-medium">Usuario</th>
                    </tr>
                </thead>

                <tbody>
                    @forelse ($this->gastos as $gasto)
                        <tr wire:key="gasto-{{ $gasto->id }}" class="border-b border-slate-100">
                            <td class="p-3 text-slate-700">{{ \Illuminate\Support\Carbon::parse($gasto->fecha_gasto)->format('d/m/Y') }}</td>
                            <td class="p-3 font-medium text-slate-900">{{ $gasto->descripcion }}</td>
                            <td class="p-3 text-slate-700">{{ $gasto->categoria?->nombre ?? 'Sin categoria' }}</td>
                            <td class="p-3 text-slate-700">
                                <span class="whitespace-nowrap rounded-full px-2.5 py-1 text-xs font-semibold {{ $gasto->origen === 'caja_dia' ? 'bg-emerald-50 text-emerald-700' : ($gasto->origen === 'inversion_extra' ? 'bg-sky-50 text-sky-700' : 'bg-slate-100 text-slate-600') }}">
                                    {{ str_replace('_', ' ', $gasto->origen ?? 'balance_general') }}
                                </span>
                            </td>
                            <td class="p-3 text-slate-700 capitalize">{{ $gasto->tipo }}</td>
                            <td class="p-3 text-slate-700 capitalize">{{ $gasto->metodo_pago }}</td>
                            <td class="p-3 text-slate-700">${{ number_format((float) $gasto->monto, 2) }}</td>
                            <td class="p-3 text-slate-700">{{ $gasto->user?->name ?? 'Sin usuario' }}</td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="8" class="p-4">
                                <div class="godslove-empty">
                                    <p class="godslove-empty-title">No hay gastos con esos filtros</p>
                                    <p class="godslove-empty-copy">Cuando registres un egreso, quedara listo para el corte y finanzas.</p>
                                </div>
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>
</div>

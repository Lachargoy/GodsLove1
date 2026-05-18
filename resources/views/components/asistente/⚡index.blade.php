<?php

use App\Services\Ai\OpenRouterAssistantService;
use Livewire\Component;

new class extends Component
{
    public string $prompt = '';

    /**
     * @var array<int, array<string, mixed>>
     */
    public array $messages = [];

    public ?string $error = null;

    public bool $loopMode = false;

    /**
     * @var array<int, array<string, mixed>>
     */
    public array $lastToolResults = [];

    /**
     * @var array<int, array<string, mixed>>
     */
    public array $lastLoopSteps = [];

    /**
     * @var array<int, array<string, mixed>>
     */
    public array $pendingConfirmations = [];

    /**
     * @var array{type: string, title: string, message: string}|null
     */
    public ?array $toast = null;

    public function mount(): void
    {
        $this->messages = session($this->historySessionKey(), []);
        $this->pendingConfirmations = session($this->confirmationsSessionKey(), []);

        if ($this->messages === []) {
            $this->messages = [
                [
                    'role' => 'assistant',
                    'content' => 'Listo. Puedo consultar inventario, buscar productos, preparar ventas, abrir/cerrar caja, crear catalogo y preparar movimientos. Para modificar datos siempre te voy a pedir confirmacion.',
                ],
            ];

            $this->persistConversation();
        }
    }

    public function enviar(OpenRouterAssistantService $assistant): void
    {
        $message = trim($this->prompt);

        if ($message === '') {
            return;
        }

        $this->prompt = '';
        $this->sendAssistantMessage($assistant, $message);
    }

    public function confirmarPendiente(OpenRouterAssistantService $assistant): void
    {
        $pending = $this->confirmablePendingConfirmation();

        if ($pending === null) {
            return;
        }

        $this->sendAssistantMessage($assistant, 'confirmar', [$pending]);
    }

    public function cancelarPendiente(OpenRouterAssistantService $assistant): void
    {
        if ($this->pendingConfirmations === []) {
            return;
        }

        $this->sendAssistantMessage($assistant, 'cancelar');
    }

    /**
     * @param  array<int, array<string, mixed>>|null  $pendingConfirmations
     */
    private function sendAssistantMessage(OpenRouterAssistantService $assistant, string $message, ?array $pendingConfirmations = null): void
    {
        $this->error = null;
        $this->lastToolResults = [];
        $pendingContext = $pendingConfirmations ?? $this->pendingConfirmations;
        $this->messages[] = [
            'role' => 'user',
            'content' => $message,
        ];

        try {
            $response = $this->loopMode
                ? $assistant->planAndExecute($this->messages, auth()->user(), $pendingContext)
                : $assistant->respond($this->messages, auth()->user(), $pendingContext);

            $this->messages = $response['messages'];
            $this->lastToolResults = $response['tool_results'];
            $this->pendingConfirmations = $pendingConfirmations === null
                ? $response['pending_confirmations']
                : $this->mergeReturnedPendingConfirmations($pendingContext, $response['pending_confirmations']);
            $this->lastLoopSteps = $response['loop_steps'] ?? [];
            $this->toast = $this->toastFromResponse($response);
            $this->persistConversation();
        } catch (\Throwable $throwable) {
            $message = $throwable->getMessage();

            $this->error = str_contains($message, 'timed out') || str_contains($message, 'cURL error 28')
                ? 'El modelo tardo demasiado en responder. Intenta de nuevo o usa un modelo mas rapido en OPENROUTER_MODEL.'
                : $message;
            $this->toast = [
                'type' => 'error',
                'title' => 'Algo se atraveso',
                'message' => $this->error,
            ];
            $this->persistConversation();
        }
    }

    public function limpiar(): void
    {
        $this->reset(['prompt', 'error', 'lastToolResults', 'lastLoopSteps', 'pendingConfirmations']);
        $this->toast = [
            'type' => 'success',
            'title' => 'Conversacion limpia',
            'message' => 'El espacio quedo listo para una nueva operacion.',
        ];
        $this->messages = [
            [
                'role' => 'assistant',
                'content' => 'Conversacion limpia. Dime que operacion quieres revisar o preparar.',
            ],
        ];

        session()->forget([
            $this->historySessionKey(),
            $this->confirmationsSessionKey(),
        ]);

        $this->persistConversation();
    }

    public function usarEjemplo(string $message): void
    {
        $this->prompt = $message;
    }

    public function dismissToast(): void
    {
        $this->toast = null;
    }

    public function getVisibleMessageCountProperty(): int
    {
        return collect($this->messages)
            ->filter(fn (array $message): bool => ! ($message['hidden'] ?? false))
            ->filter(fn (array $message): bool => in_array($message['role'] ?? null, ['user', 'assistant'], true))
            ->count();
    }

    /**
     * @return array{title: string, operation: string, lines: array<int, string>, meta: array<int, array{label: string, value: string}>, total: string|null}|null
     */
    public function getConfirmationModalProperty(): ?array
    {
        $pending = $this->confirmablePendingConfirmation();

        if ($pending === null) {
            return null;
        }

        $operation = (string) ($pending['operation'] ?? 'operacion');
        $summary = is_array($pending['summary'] ?? null) ? $pending['summary'] : [];

        return [
            'title' => 'Confirmar '.$this->operationLabel($operation),
            'operation' => $this->operationLabel($operation),
            'lines' => $this->confirmationLines($summary),
            'meta' => $this->confirmationMeta($summary),
            'total' => is_numeric(data_get($summary, 'total')) ? '$'.number_format((float) data_get($summary, 'total'), 2) : null,
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    private function confirmablePendingConfirmation(): ?array
    {
        return collect($this->pendingConfirmations)
            ->first(fn (array $pending): bool => filled($pending['confirmation_token'] ?? null));
    }

    private function operationLabel(string $operation): string
    {
        return match ($operation) {
            'venta' => 'venta',
            'abrir_caja' => 'apertura de caja',
            'cerrar_caja' => 'cierre de caja',
            'movimiento_inventario' => 'movimiento de inventario',
            'alta_insumo' => 'alta de insumo',
            'alta_categoria' => 'alta de categoria',
            'alta_producto' => 'alta de producto',
            'receta_producto' => 'receta de producto',
            'opciones_producto' => 'opciones de producto',
            default => str_replace('_', ' ', $operation),
        };
    }

    /**
     * @param  array<string, mixed>  $summary
     * @return array<int, string>
     */
    private function confirmationLines(array $summary): array
    {
        $lines = collect(data_get($summary, 'lineas', []))
            ->filter(fn (mixed $line): bool => is_array($line))
            ->map(fn (array $line): string => number_format((float) ($line['cantidad'] ?? 0), 2).' x '.($line['nombre'] ?? 'producto').' = $'.number_format((float) ($line['subtotal'] ?? 0), 2))
            ->values();

        if ($lines->isNotEmpty()) {
            return $lines->all();
        }

        $items = collect(data_get($summary, 'items', []))
            ->filter(fn (mixed $item): bool => is_array($item))
            ->map(fn (array $item): string => number_format((float) ($item['cantidad'] ?? $item['cantidad_requerida'] ?? 0), 2).' x '.($item['nombre'] ?? 'item'))
            ->values();

        if ($items->isNotEmpty()) {
            return $items->all();
        }

        $options = collect(data_get($summary, 'opciones', []))
            ->filter(fn (mixed $option): bool => is_array($option))
            ->map(fn (array $option): string => ($option['nombre'] ?? 'opcion').' - '.number_format((float) ($option['cantidad_por_seleccion'] ?? 0), 3).' '.($option['unidad'] ?? ''))
            ->values();

        if ($options->isNotEmpty()) {
            return $options->all();
        }

        foreach (['producto', 'insumo', 'categoria'] as $key) {
            if (filled($summary[$key] ?? null)) {
                return [(string) $summary[$key]];
            }
        }

        return ['Operacion preparada para guardar.'];
    }

    /**
     * @param  array<string, mixed>  $summary
     * @return array<int, array{label: string, value: string}>
     */
    private function confirmationMeta(array $summary): array
    {
        $fields = [
            'metodo_pago' => 'Metodo',
            'subtotal' => 'Subtotal',
            'descuento' => 'Descuento',
            'monto_inicial' => 'Monto inicial',
            'monto_real_contado' => 'Monto contado',
            'diferencia' => 'Diferencia',
            'producto' => 'Producto',
            'grupo.nombre' => 'Grupo',
            'grupo.requeridas' => 'Requeridas',
            'tipo' => 'Tipo',
            'nombre' => 'Nombre',
            'unidad_medida' => 'Unidad',
            'cantidad_actual' => 'Stock inicial',
            'cantidad_minima' => 'Stock minimo',
            'costo_unitario' => 'Costo unitario',
            'caja.monto_esperado_efectivo' => 'Efectivo esperado',
            'caja.total_ventas' => 'Ventas del turno',
        ];

        return collect($fields)
            ->map(function (string $label, string $path) use ($summary): ?array {
                $value = data_get($summary, $path);

                if ($value === null || $value === '') {
                    return null;
                }

                if (is_numeric($value) && (str_contains($path, 'monto') || in_array($path, ['subtotal', 'descuento', 'diferencia', 'costo_unitario', 'caja.total_ventas'], true))) {
                    $value = '$'.number_format((float) $value, 2);
                }

                return [
                    'label' => $label,
                    'value' => is_bool($value) ? ($value ? 'Si' : 'No') : (string) $value,
                ];
            })
            ->filter()
            ->values()
            ->all();
    }

    private function persistConversation(): void
    {
        session([
            $this->historySessionKey() => $this->messages,
            $this->confirmationsSessionKey() => $this->pendingConfirmations,
        ]);
    }

    /**
     * @param  array<int, array<string, mixed>>  $sent
     * @param  array<int, array<string, mixed>>  $returned
     * @return array<int, array<string, mixed>>
     */
    private function mergeReturnedPendingConfirmations(array $sent, array $returned): array
    {
        $sentTokens = collect($sent)
            ->pluck('confirmation_token')
            ->filter()
            ->all();

        $remainingOriginal = collect($this->pendingConfirmations)
            ->reject(fn (array $pending): bool => in_array($pending['confirmation_token'] ?? null, $sentTokens, true));

        return $remainingOriginal
            ->merge($returned)
            ->values()
            ->all();
    }

    private function historySessionKey(): string
    {
        return 'asistente.messages.'.(auth()->id() ?: 'guest');
    }

    private function confirmationsSessionKey(): string
    {
        return 'asistente.confirmations.'.(auth()->id() ?: 'guest');
    }

    /**
     * @param  array<string, mixed>  $response
     * @return array{type: string, title: string, message: string}|null
     */
    private function toastFromResponse(array $response): ?array
    {
        $toolResult = collect($response['tool_results'] ?? [])
            ->map(fn (array $tool): mixed => $tool['result'] ?? null)
            ->first(fn (mixed $result): bool => is_array($result) && filled($result['status'] ?? null));

        if (is_array($toolResult)) {
            $status = $toolResult['status'] ?? null;
            $operation = str_replace('_', ' ', (string) ($toolResult['operacion'] ?? 'operacion'));

            if ($status === 'confirmed') {
                return [
                    'type' => 'success',
                    'title' => 'Listo, guardado',
                    'message' => $operation === 'venta'
                        ? 'Venta registrada correctamente.'
                        : 'Operacion confirmada: '.$operation.'.',
                ];
            }

            if ($status === 'requires_confirmation') {
                return [
                    'type' => 'pending',
                    'title' => 'Lista para confirmar',
                    'message' => 'Revise el resumen de '.$operation.' y espero tu confirmacion.',
                ];
            }

            if ($status === 'blocked' || $status === 'error') {
                return [
                    'type' => 'warning',
                    'title' => 'Necesito revisar algo',
                    'message' => 'La operacion no se ejecuto. Mira el mensaje del asistente.',
                ];
            }
        }

        $reply = (string) ($response['reply'] ?? '');

        if (str_contains($reply, 'cancele la operacion pendiente')) {
            return [
                'type' => 'success',
                'title' => 'Operacion cancelada',
                'message' => 'No se guardo ningun cambio.',
            ];
        }

        if (($response['loop_steps'] ?? []) !== []) {
            return [
                'type' => str_contains($reply, 'Loop bloqueado') ? 'warning' : 'success',
                'title' => str_contains($reply, 'Loop pausado') ? 'Proceso pausado' : 'Proceso actualizado',
                'message' => str_contains($reply, 'Loop pausado')
                    ? 'Hay una operacion esperando confirmacion.'
                    : 'El agente termino de procesar los pasos posibles.',
            ];
        }

        return null;
    }
};
?>

<div class="relative mx-auto flex w-full max-w-[1440px] flex-col gap-5 px-4 py-5 sm:px-6 lg:px-8">
    @php
        $confirmationModal = $this->confirmationModal;
    @endphp

    @if ($toast)
        <div
            wire:key="assistant-toast-{{ md5(json_encode($toast)) }}"
            class="fixed right-4 top-4 z-50 w-[min(92vw,360px)] overflow-hidden rounded-2xl border border-white/80 bg-white/95 p-4 shadow-xl shadow-rose-950/10 ring-1 ring-rose-100 backdrop-blur"
            x-data="{ show: true }"
            x-init="setTimeout(() => show = false, 5200)"
            x-show="show"
            x-transition.opacity.duration.200ms
        >
            <div class="flex gap-3">
                <div @class([
                    'flex size-10 shrink-0 items-center justify-center rounded-2xl text-sm font-black text-white',
                    'bg-emerald-500' => $toast['type'] === 'success',
                    'bg-amber-500' => in_array($toast['type'], ['pending', 'warning'], true),
                    'bg-rose-500' => $toast['type'] === 'error',
                ])>
                    {{ $toast['type'] === 'success' ? 'OK' : ($toast['type'] === 'error' ? '!' : '...') }}
                </div>
                <div class="min-w-0 flex-1">
                    <p class="text-sm font-black text-slate-950">{{ $toast['title'] }}</p>
                    <p class="mt-1 text-sm leading-5 text-slate-600">{{ $toast['message'] }}</p>
                </div>
                <button type="button" wire:click="dismissToast" class="flex size-8 items-center justify-center rounded-full text-slate-400 transition hover:bg-rose-50 hover:text-rose-600">
                    x
                </button>
            </div>
            <div class="mt-4 h-1 overflow-hidden rounded-full bg-rose-50">
                <div @class([
                    'h-full rounded-full',
                    'bg-emerald-400' => $toast['type'] === 'success',
                    'bg-amber-400' => in_array($toast['type'], ['pending', 'warning'], true),
                    'bg-rose-400' => $toast['type'] === 'error',
                ]) style="width: 68%"></div>
            </div>
        </div>
    @endif

    @if ($confirmationModal)
        <div class="fixed inset-0 z-40 flex items-center justify-center bg-slate-950/30 px-4 py-6 backdrop-blur-sm">
            <div class="w-full max-w-lg overflow-hidden rounded-[2rem] border border-white/80 bg-white shadow-2xl shadow-rose-950/20 ring-1 ring-rose-100">
                <div class="bg-gradient-to-br from-rose-50 via-white to-emerald-50 px-5 py-5 sm:px-6">
                    <div class="flex items-start justify-between gap-4">
                        <div>
                            <p class="text-xs font-black uppercase tracking-[0.16em] text-rose-500">Confirmacion segura</p>
                            <h2 class="mt-1 text-2xl font-black text-slate-950">{{ $confirmationModal['title'] }}</h2>
                            <p class="mt-2 text-sm leading-6 text-slate-600">Revisa los datos antes de guardar. El agente no ejecuta este cambio sin tu boton de confirmacion.</p>
                        </div>
                        <div class="flex size-11 shrink-0 items-center justify-center rounded-2xl bg-rose-500 text-sm font-black text-white shadow-sm shadow-rose-900/20">GL</div>
                    </div>
                </div>

                <div class="space-y-4 px-5 py-5 sm:px-6">
                    <div class="rounded-3xl bg-slate-50 p-4">
                        <div class="flex items-center justify-between gap-3">
                            <p class="text-xs font-black uppercase tracking-[0.12em] text-slate-500">{{ $confirmationModal['operation'] }}</p>
                            @if ($confirmationModal['total'])
                                <span class="rounded-full bg-emerald-100 px-3 py-1 text-sm font-black text-emerald-800">{{ $confirmationModal['total'] }}</span>
                            @endif
                        </div>

                        <div class="mt-3 space-y-2">
                            @foreach ($confirmationModal['lines'] as $lineIndex => $line)
                                <div wire:key="confirmation-line-{{ $lineIndex }}" class="rounded-2xl bg-white px-3 py-2 text-sm font-bold text-slate-800 ring-1 ring-slate-100">
                                    {{ $line }}
                                </div>
                            @endforeach
                        </div>
                    </div>

                    @if ($confirmationModal['meta'] !== [])
                        <div class="grid grid-cols-1 gap-2 sm:grid-cols-2">
                            @foreach ($confirmationModal['meta'] as $metaIndex => $meta)
                                <div wire:key="confirmation-meta-{{ $metaIndex }}" class="rounded-2xl border border-rose-100 bg-rose-50/60 px-3 py-2">
                                    <p class="text-[11px] font-black uppercase tracking-[0.12em] text-rose-500">{{ $meta['label'] }}</p>
                                    <p class="mt-1 text-sm font-black text-slate-950">{{ $meta['value'] }}</p>
                                </div>
                            @endforeach
                        </div>
                    @endif
                </div>

                <div class="flex flex-col-reverse gap-2 border-t border-rose-100 bg-white px-5 py-4 sm:flex-row sm:justify-end sm:px-6">
                    <button type="button" wire:click="cancelarPendiente" wire:loading.attr="disabled" class="inline-flex items-center justify-center rounded-full bg-slate-100 px-5 py-3 text-sm font-black text-slate-700 transition hover:bg-slate-200 disabled:cursor-not-allowed disabled:opacity-60">
                        Cancelar
                    </button>
                    <button type="button" wire:click="confirmarPendiente" wire:loading.attr="disabled" class="inline-flex items-center justify-center rounded-full bg-rose-500 px-5 py-3 text-sm font-black text-white shadow-sm shadow-rose-900/20 transition hover:bg-rose-600 disabled:cursor-not-allowed disabled:opacity-60">
                        Confirmar y guardar
                    </button>
                </div>
            </div>
        </div>
    @endif

    <header class="rounded-3xl border border-white/80 bg-white/80 px-5 py-4 shadow-sm shadow-rose-950/5 ring-1 ring-rose-100/70 backdrop-blur sm:px-6">
        <div class="flex flex-col gap-4 lg:flex-row lg:items-center lg:justify-between">
            <div class="flex min-w-0 items-center gap-4">
                <div class="godslove-mark size-12 text-base"><span>GL</span></div>
                <div class="min-w-0">
                    <div class="flex flex-wrap items-center gap-2">
                        <p class="text-xs font-black uppercase tracking-[0.16em] text-rose-500">GodsLove OS</p>
                        <span class="h-1 w-1 rounded-full bg-rose-300"></span>
                        <p class="text-xs font-bold text-slate-500">{{ $loopMode ? 'Plan + ejecucion' : 'Chat directo' }}</p>
                    </div>
                    <h1 class="mt-1 text-2xl font-black tracking-normal text-slate-950 sm:text-3xl">Asistente de operaciones</h1>
                </div>
            </div>

            <div class="flex flex-wrap items-center gap-2 text-xs font-bold text-slate-600">
                <span class="rounded-full bg-rose-50 px-3 py-2 text-rose-700">{{ $this->visibleMessageCount }} mensajes</span>
                <span class="rounded-full bg-amber-50 px-3 py-2 text-amber-700">{{ count($pendingConfirmations) }} pendientes</span>
                <span class="max-w-full truncate rounded-full bg-white px-3 py-2 ring-1 ring-rose-100">{{ config('services.openrouter.model') }}</span>
            </div>
        </div>
    </header>

    @if (! config('services.openrouter.key'))
        <div class="rounded-2xl border border-amber-200 bg-amber-50 px-4 py-3 text-sm font-medium text-amber-900">
            Falta OPENROUTER_API_KEY en el .env. La interfaz ya esta lista, pero no podra llamar al modelo hasta configurar la llave.
        </div>
    @endif

    @if ($error)
        <div class="rounded-2xl border border-rose-200 bg-rose-50 px-4 py-3 text-sm font-medium text-rose-800">
            {{ $error }}
        </div>
    @endif

    <main class="grid min-h-[calc(100vh-160px)] gap-5 xl:grid-cols-[minmax(0,1fr)_340px]">
        <section class="flex min-h-[720px] flex-col overflow-hidden rounded-3xl border border-white/80 bg-white/85 shadow-lg shadow-rose-950/5 ring-1 ring-rose-100/70 backdrop-blur">
            <div class="flex flex-col gap-3 border-b border-rose-100/80 px-5 py-4 sm:flex-row sm:items-center sm:justify-between">
                <div class="flex items-center gap-3">
                    <div class="flex size-10 items-center justify-center rounded-2xl bg-rose-500 text-sm font-black text-white shadow-sm shadow-rose-900/20">IA</div>
                    <div>
                        <h2 class="text-base font-black text-slate-950">Ventana de trabajo</h2>
                        <p class="text-xs font-semibold text-slate-500">Operaciones con confirmacion segura</p>
                    </div>
                </div>
                <div class="flex flex-wrap items-center gap-2">
                    <label class="inline-flex items-center gap-2 rounded-full bg-slate-100 px-3 py-2 text-xs font-black text-slate-700">
                        <input type="checkbox" wire:model.live="loopMode" class="rounded border-slate-300 text-rose-500 focus:ring-rose-400">
                        Loop
                    </label>
                    <button type="button" wire:click="limpiar" class="rounded-full bg-white px-3 py-2 text-xs font-black text-slate-600 ring-1 ring-rose-100 transition hover:bg-rose-50 hover:text-rose-700">
                        Limpiar
                    </button>
                </div>
            </div>

            <div class="flex-1 overflow-y-auto bg-gradient-to-b from-rose-50/45 via-white to-emerald-50/30">
                <div class="mx-auto flex w-full max-w-4xl flex-col gap-6 px-4 py-6 sm:px-6">
                    @foreach ($messages as $index => $message)
                        @continue($message['hidden'] ?? false)
                        @continue(($message['role'] ?? '') === 'tool')
                        @continue(($message['content'] ?? '') === null)

                        @php
                            $isUser = ($message['role'] ?? '') === 'user';
                        @endphp

                        <article wire:key="assistant-message-{{ $index }}" @class(['flex gap-3', 'justify-end' => $isUser])>
                            @if (! $isUser)
                                <div class="mt-1 flex size-9 shrink-0 items-center justify-center rounded-2xl bg-rose-500 text-xs font-black text-white shadow-sm shadow-rose-900/20">
                                    GL
                                </div>
                            @endif

                            <div @class(['min-w-0 max-w-[820px]', 'text-right' => $isUser])>
                                <div @class(['mb-1 flex items-center gap-2 text-xs font-bold text-slate-400', 'justify-end' => $isUser])>
                                    <span>{{ $isUser ? 'Tu' : 'GodsLove AI' }}</span>
                                    <span>#{{ $index + 1 }}</span>
                                </div>
                                <div
                                    @class([
                                        'whitespace-pre-wrap px-4 py-3 text-sm leading-6 shadow-sm sm:px-5',
                                        'rounded-3xl rounded-tr-md bg-slate-950 text-white shadow-slate-900/10' => $isUser,
                                        'rounded-3xl rounded-tl-md border border-rose-100 bg-white text-slate-800' => ! $isUser,
                                    ])
                                >{{ (string) $message['content'] }}</div>
                            </div>

                            @if ($isUser)
                                <div class="mt-1 flex size-9 shrink-0 items-center justify-center rounded-2xl bg-slate-950 text-xs font-black text-white">
                                    TU
                                </div>
                            @endif
                        </article>
                    @endforeach

                    <div wire:loading.flex wire:target="enviar" class="items-start gap-3">
                        <div class="flex size-9 shrink-0 items-center justify-center rounded-2xl bg-rose-500 text-xs font-black text-white">GL</div>
                        <div class="w-full max-w-lg rounded-3xl rounded-tl-md border border-rose-100 bg-white px-5 py-4 shadow-sm">
                            <p class="text-sm font-black text-slate-900">Pensando con tools seguras</p>
                            <div class="mt-3 flex gap-1.5">
                                <span class="size-2 rounded-full bg-rose-300"></span>
                                <span class="size-2 rounded-full bg-amber-300"></span>
                                <span class="size-2 rounded-full bg-emerald-300"></span>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <form wire:submit="enviar" class="border-t border-rose-100 bg-white/95 p-4 sm:p-5">
                <div class="mx-auto flex w-full max-w-4xl flex-col gap-3">
                    <textarea
                        wire:model="prompt"
                        rows="3"
                        class="min-h-24 w-full resize-none rounded-3xl border-rose-100 bg-rose-50/40 px-4 py-4 text-sm leading-6 shadow-inner shadow-rose-950/5 focus:border-rose-300 focus:bg-white focus:ring-rose-200"
                        placeholder="Ej. registra una venta de 2 conos sencillos en efectivo"
                    ></textarea>
                    <div class="flex flex-col-reverse gap-3 sm:flex-row sm:items-center sm:justify-between">
                        <p class="text-xs font-medium text-slate-500">{{ $loopMode ? 'El loop se pausa si hay una operacion por confirmar.' : 'Las escrituras se preparan primero; confirmar guarda.' }}</p>
                        <button type="submit" class="inline-flex items-center justify-center rounded-full bg-rose-500 px-5 py-3 text-sm font-black text-white shadow-sm shadow-rose-900/20 transition hover:bg-rose-600 disabled:cursor-not-allowed disabled:opacity-60" wire:loading.attr="disabled" wire:target="enviar">
                            {{ $loopMode ? 'Ejecutar plan' : 'Enviar' }}
                        </button>
                    </div>
                </div>
            </form>
        </section>

        <aside class="flex flex-col gap-4">
            <section class="rounded-3xl border border-white/80 bg-white/80 p-4 shadow-sm shadow-rose-950/5 ring-1 ring-rose-100/70 backdrop-blur">
                <div class="flex items-center justify-between gap-3">
                    <div>
                        <p class="text-xs font-black uppercase tracking-[0.14em] text-rose-500">Estado</p>
                        <h2 class="mt-1 text-lg font-black text-slate-950">{{ count($pendingConfirmations) > 0 ? 'Esperando confirmacion' : 'Listo para operar' }}</h2>
                    </div>
                    <div @class([
                        'size-3 rounded-full',
                        'bg-amber-400' => count($pendingConfirmations) > 0,
                        'bg-emerald-400' => count($pendingConfirmations) === 0,
                    ])></div>
                </div>
                <div class="mt-4 grid grid-cols-2 gap-2">
                    <div class="rounded-2xl bg-rose-50 p-3">
                        <p class="text-xs font-bold text-rose-700">Mensajes</p>
                        <p class="mt-1 text-2xl font-black text-slate-950">{{ $this->visibleMessageCount }}</p>
                    </div>
                    <div class="rounded-2xl bg-amber-50 p-3">
                        <p class="text-xs font-bold text-amber-700">Pendientes</p>
                        <p class="mt-1 text-2xl font-black text-slate-950">{{ count($pendingConfirmations) }}</p>
                    </div>
                </div>
            </section>

            @if ($lastLoopSteps !== [])
                <section class="rounded-3xl border border-white/80 bg-white/80 p-4 shadow-sm ring-1 ring-rose-100/70">
                    <div class="flex items-center justify-between gap-3">
                        <h2 class="text-sm font-black text-slate-950">Proceso</h2>
                        <span class="rounded-full bg-emerald-50 px-2.5 py-1 text-xs font-black text-emerald-700">{{ count($lastLoopSteps) }} pasos</span>
                    </div>
                    <div class="mt-4 space-y-3">
                        @foreach ($lastLoopSteps as $loopIndex => $step)
                            <div wire:key="loop-step-{{ $loopIndex }}" class="rounded-2xl bg-slate-50 px-3 py-3">
                                <div class="flex items-center justify-between gap-3">
                                    <p class="text-xs font-black uppercase tracking-[0.12em] text-slate-500">{{ $step['tipo'] ?? 'paso' }}</p>
                                    <span @class([
                                        'rounded-full px-2 py-1 text-xs font-black',
                                        'bg-emerald-100 text-emerald-800' => ($step['estado'] ?? '') === 'completed',
                                        'bg-amber-100 text-amber-800' => in_array(($step['estado'] ?? ''), ['waiting_confirmation', 'waiting_input'], true),
                                        'bg-rose-100 text-rose-800' => ($step['estado'] ?? '') === 'blocked',
                                        'bg-slate-200 text-slate-700' => ! in_array(($step['estado'] ?? ''), ['completed', 'waiting_confirmation', 'waiting_input', 'blocked'], true),
                                    ])>{{ $step['estado'] ?? 'running' }}</span>
                                </div>
                                <p class="mt-2 line-clamp-4 text-xs leading-5 text-slate-600">{{ $step['resumen'] ?? '' }}</p>
                            </div>
                        @endforeach
                    </div>
                </section>
            @endif

            <section class="rounded-3xl border border-white/80 bg-white/80 p-4 shadow-sm ring-1 ring-rose-100/70">
                <h2 class="text-sm font-black text-slate-950">Atajos</h2>
                <div class="mt-3 flex flex-col gap-2">
                    @foreach ([
                        'registra una venta de 2 conos sencillos en efectivo',
                        'Que inventario esta bajo?',
                        'Dame el resumen de caja',
                        'Prepara abrir caja con 500 pesos',
                    ] as $example)
                        <button
                            type="button"
                            wire:key="assistant-shortcut-{{ md5($example) }}"
                            wire:click="usarEjemplo(@js($example))"
                            class="rounded-2xl bg-white px-3 py-2.5 text-left text-sm font-semibold leading-5 text-slate-700 ring-1 ring-rose-100 transition hover:bg-rose-50 hover:text-rose-700"
                        >
                            {{ $example }}
                        </button>
                    @endforeach
                </div>
            </section>

            <section class="rounded-3xl border border-white/80 bg-white/80 p-4 shadow-sm ring-1 ring-rose-100/70">
                <h2 class="text-sm font-black text-slate-950">Ultima actividad</h2>
                @if ($lastToolResults === [])
                    <p class="mt-3 text-sm leading-6 text-slate-500">Aqui aparece la operacion cuando una tool prepara, confirma o bloquea algo.</p>
                @else
                    <div class="mt-3 flex flex-col gap-2">
                        @foreach ($lastToolResults as $toolIndex => $tool)
                            <div wire:key="tool-result-{{ $toolIndex }}" class="rounded-2xl bg-slate-50 px-3 py-2.5">
                                <p class="text-sm font-black text-slate-900">{{ data_get($tool, 'result.operacion', $tool['name']) }}</p>
                                <p class="mt-1 text-xs font-semibold text-slate-500">{{ data_get($tool, 'result.status', 'consulta') }}</p>
                            </div>
                        @endforeach
                    </div>
                @endif
            </section>
        </aside>
    </main>
</div>

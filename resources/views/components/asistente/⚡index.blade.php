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

        $this->error = null;
        $this->lastToolResults = [];
        $this->prompt = '';
        $this->messages[] = [
            'role' => 'user',
            'content' => $message,
        ];

        try {
            $response = $this->loopMode
                ? $assistant->planAndExecute($this->messages, auth()->user(), $this->pendingConfirmations)
                : $assistant->respond($this->messages, auth()->user(), $this->pendingConfirmations);

            $this->messages = $response['messages'];
            $this->lastToolResults = $response['tool_results'];
            $this->pendingConfirmations = $response['pending_confirmations'];
            $this->lastLoopSteps = $response['loop_steps'] ?? [];
            $this->persistConversation();
        } catch (\Throwable $throwable) {
            $message = $throwable->getMessage();

            $this->error = str_contains($message, 'timed out') || str_contains($message, 'cURL error 28')
                ? 'El modelo tardo demasiado en responder. Intenta de nuevo o usa un modelo mas rapido en OPENROUTER_MODEL.'
                : $message;
            $this->persistConversation();
        }
    }

    public function limpiar(): void
    {
        $this->reset(['prompt', 'error', 'lastToolResults', 'lastLoopSteps', 'pendingConfirmations']);
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

    public function getVisibleMessageCountProperty(): int
    {
        return collect($this->messages)
            ->filter(fn (array $message): bool => ! ($message['hidden'] ?? false))
            ->filter(fn (array $message): bool => in_array($message['role'] ?? null, ['user', 'assistant'], true))
            ->count();
    }

    private function persistConversation(): void
    {
        session([
            $this->historySessionKey() => $this->messages,
            $this->confirmationsSessionKey() => $this->pendingConfirmations,
        ]);
    }

    private function historySessionKey(): string
    {
        return 'asistente.messages.'.(auth()->id() ?: 'guest');
    }

    private function confirmationsSessionKey(): string
    {
        return 'asistente.confirmations.'.(auth()->id() ?: 'guest');
    }
};
?>

<div class="mx-auto flex w-full max-w-[1500px] flex-col gap-5 px-4 py-5 sm:px-6 lg:px-8">
    <header class="overflow-hidden rounded-lg border border-slate-200 bg-white shadow-sm">
        <div class="grid gap-0 lg:grid-cols-[minmax(0,1fr)_420px]">
            <div class="relative border-b border-slate-200 p-5 sm:p-6 lg:border-b-0 lg:border-r">
                <div class="absolute inset-0 opacity-[0.45]" style="background-image: linear-gradient(to right, rgba(15,23,42,.06) 1px, transparent 1px), linear-gradient(to bottom, rgba(15,23,42,.05) 1px, transparent 1px); background-size: 28px 28px;"></div>
                <div class="relative max-w-4xl">
                    <div class="flex flex-wrap items-center gap-2">
                        <span class="inline-flex items-center gap-2 rounded-md border border-emerald-200 bg-emerald-50 px-3 py-1.5 text-xs font-black uppercase tracking-[0.14em] text-emerald-800">
                            <span class="h-2 w-2 rounded-full bg-emerald-500"></span>
                            Asistente operativo
                        </span>
                        <span class="rounded-md border border-slate-200 bg-white px-3 py-1.5 text-xs font-bold text-slate-600">
                            {{ $loopMode ? 'Plan + ejecucion' : 'Chat normal' }}
                        </span>
                    </div>

                    <div class="mt-5 flex flex-col gap-3">
                        <h1 class="max-w-3xl text-3xl font-black tracking-normal text-slate-950 sm:text-4xl lg:text-5xl">
                            Consola IA de operaciones
                        </h1>
                        <p class="max-w-3xl text-sm leading-6 text-slate-600 sm:text-base">
                            Planea, consulta tools, prepara cambios y deja cada escritura esperando tu confirmacion. El historial vive en esta sesion para que no pierdas contexto mientras trabajas.
                        </p>
                    </div>
                </div>
            </div>

            <div class="grid grid-cols-2 gap-px bg-slate-200">
                <div class="bg-white p-4">
                    <p class="text-xs font-black uppercase tracking-[0.12em] text-slate-500">Mensajes</p>
                    <p class="mt-2 text-3xl font-black text-slate-950">{{ $this->visibleMessageCount }}</p>
                </div>
                <div class="bg-white p-4">
                    <p class="text-xs font-black uppercase tracking-[0.12em] text-slate-500">Pendientes</p>
                    <p class="mt-2 text-3xl font-black text-amber-700">{{ count($pendingConfirmations) }}</p>
                </div>
                <div class="col-span-2 bg-white p-4">
                    <p class="text-xs font-black uppercase tracking-[0.12em] text-slate-500">Modelo activo</p>
                    <p class="mt-2 break-words text-sm font-black text-slate-950">{{ config('services.openrouter.model') }}</p>
                </div>
            </div>
        </div>
    </header>

    @if (! config('services.openrouter.key'))
        <div class="rounded-lg border border-amber-200 bg-amber-50 px-4 py-3 text-sm font-medium text-amber-900">
            Falta OPENROUTER_API_KEY en el .env. La interfaz ya esta lista, pero no podra llamar al modelo hasta configurar la llave.
        </div>
    @endif

    @if ($error)
        <div class="rounded-lg border border-red-200 bg-red-50 px-4 py-3 text-sm font-medium text-red-800">
            {{ $error }}
        </div>
    @endif

    <div class="grid min-h-[calc(100vh-220px)] gap-5 xl:grid-cols-[minmax(0,1fr)_380px]">
        <section class="flex min-h-[720px] flex-col overflow-hidden rounded-lg border border-slate-200 bg-white shadow-sm">
            <div class="flex flex-col gap-3 border-b border-slate-200 bg-white px-5 py-4 sm:flex-row sm:items-center sm:justify-between">
                <div class="flex items-center gap-3">
                    <div class="flex h-10 w-10 items-center justify-center rounded-md bg-slate-950 text-sm font-black text-white">IA</div>
                    <div>
                        <h2 class="text-base font-black text-slate-950">Historial operativo</h2>
                        <p class="text-xs font-semibold text-slate-500">{{ $this->visibleMessageCount }} mensajes visibles</p>
                    </div>
                </div>
                <div class="flex flex-wrap gap-2">
                    <span @class([
                        'rounded-md px-3 py-1.5 text-xs font-black',
                        'bg-emerald-100 text-emerald-800' => $loopMode,
                        'bg-slate-100 text-slate-700' => ! $loopMode,
                    ])>
                        {{ $loopMode ? 'Loop activo' : 'Chat directo' }}
                    </span>
                    <button type="button" wire:click="limpiar" class="rounded-md border border-slate-300 bg-white px-3 py-1.5 text-xs font-black text-slate-700 transition hover:border-slate-500 hover:bg-slate-50">
                        Limpiar
                    </button>
                </div>
            </div>

            <div class="flex-1 overflow-y-auto bg-[#f8fafc]">
                <div class="mx-auto flex w-full max-w-5xl flex-col gap-5 p-4 sm:p-6">
                @foreach ($messages as $index => $message)
                    @continue($message['hidden'] ?? false)
                    @continue(($message['role'] ?? '') === 'tool')
                    @continue(($message['content'] ?? '') === null)

                    @php
                        $isUser = ($message['role'] ?? '') === 'user';
                    @endphp

                    <article wire:key="assistant-message-{{ $index }}" @class(['grid gap-3', 'grid-cols-[1fr_36px]' => $isUser, 'grid-cols-[36px_1fr]' => ! $isUser])>
                        @if (! $isUser)
                            <div class="mt-6 flex h-9 w-9 shrink-0 items-center justify-center rounded-md bg-emerald-600 text-sm font-black text-white shadow-sm shadow-emerald-900/20">
                                IA
                            </div>
                        @endif

                        <div @class(['min-w-0 space-y-2', 'text-right' => $isUser])>
                            <div @class(['flex items-center gap-2 text-xs font-black text-slate-500', 'justify-end' => $isUser])>
                                <span class="rounded-md bg-white px-2 py-1 ring-1 ring-slate-200">{{ $isUser ? 'Tu' : 'Operador IA' }}</span>
                                <span class="font-semibold text-slate-400">#{{ $index + 1 }}</span>
                            </div>
                            <div
                                @class([
                                    'whitespace-pre-wrap rounded-lg px-4 py-3 text-sm leading-6 shadow-sm sm:px-5 sm:py-4',
                                    'ml-auto max-w-[760px] bg-slate-950 text-white shadow-slate-900/10' => $isUser,
                                    'max-w-[860px] border border-slate-200 bg-white text-slate-800' => ! $isUser,
                                ])
                            >{{ (string) $message['content'] }}</div>
                        </div>

                        @if ($isUser)
                            <div class="mt-6 flex h-9 w-9 shrink-0 items-center justify-center rounded-md bg-slate-950 text-sm font-black text-white shadow-sm">
                                TU
                            </div>
                        @endif
                    </article>
                @endforeach

                <div wire:loading.flex wire:target="enviar" class="justify-start gap-3">
                    <div class="flex h-9 w-9 shrink-0 items-center justify-center rounded-md bg-emerald-600 text-sm font-black text-white">
                        IA
                    </div>
                    <div class="w-full max-w-xl rounded-lg border border-emerald-200 bg-white p-4 shadow-sm">
                        <div class="flex items-center justify-between gap-3">
                            <p class="text-sm font-black text-emerald-900">Consultando modelo y herramientas</p>
                            <span class="rounded-md bg-emerald-50 px-2 py-1 text-xs font-black text-emerald-700">activo</span>
                        </div>
                        <div class="mt-3 grid gap-2">
                            <div class="h-2 w-11/12 rounded-full bg-emerald-100"></div>
                            <div class="h-2 w-8/12 rounded-full bg-slate-100"></div>
                            <div class="h-2 w-10/12 rounded-full bg-slate-100"></div>
                        </div>
                    </div>
                </div>
                </div>
            </div>

            <form wire:submit="enviar" class="border-t border-slate-200 bg-white p-4 sm:p-5">
                <div class="mx-auto flex w-full max-w-5xl flex-col gap-3">
                    <div class="flex flex-wrap items-center justify-between gap-2">
                        <label class="inline-flex items-center gap-3 rounded-md border border-slate-200 bg-slate-50 px-3 py-2 text-sm font-black text-slate-800">
                            <input type="checkbox" wire:model.live="loopMode" class="rounded border-slate-300 text-emerald-600 focus:ring-emerald-500">
                            Planear y ejecutar
                        </label>
                        <p class="text-xs font-semibold text-slate-500">
                            {{ $loopMode ? 'Maximo 6 pasos; pausa automatica con confirmation_token.' : 'Modo directo: consulta o prepara una sola respuesta.' }}
                        </p>
                    </div>
                    <textarea
                        wire:model="prompt"
                        rows="3"
                        class="min-h-28 w-full resize-none rounded-lg border-slate-300 bg-slate-50 text-sm leading-6 shadow-inner focus:border-emerald-500 focus:bg-white focus:ring-emerald-500"
                        placeholder="Ej. Da de alta azucar morena con 5 kg, minimo 1 kg y costo 22 pesos"
                    ></textarea>
                    <div class="flex flex-col-reverse gap-3 sm:flex-row sm:items-center sm:justify-between">
                        <p class="text-xs font-medium text-slate-500">Las escrituras quedan preparadas; tu confirmacion guarda los cambios.</p>
                        <button type="submit" class="inline-flex items-center justify-center rounded-md bg-slate-950 px-5 py-3 text-sm font-black text-white shadow-sm transition hover:bg-slate-800 disabled:cursor-not-allowed disabled:opacity-60" wire:loading.attr="disabled" wire:target="enviar">
                            {{ $loopMode ? 'Ejecutar plan' : 'Enviar mensaje' }}
                        </button>
                    </div>
                </div>
            </form>
        </section>

        <aside class="grid content-start gap-4">
            <section class="overflow-hidden rounded-lg border border-slate-200 bg-white shadow-sm">
                <div class="border-b border-slate-200 bg-slate-950 px-4 py-4 text-white">
                    <p class="text-xs font-black uppercase tracking-[0.14em] text-emerald-300">Estado del agente</p>
                    <h2 class="mt-1 text-lg font-black">Panel de control</h2>
                </div>
                <div class="grid gap-px bg-slate-200">
                    <div class="bg-white px-4 py-3">
                        <p class="text-xs font-black uppercase tracking-[0.12em] text-slate-500">Modo</p>
                        <p class="mt-1 text-sm font-black text-slate-950">{{ $loopMode ? 'Plan + ejecucion' : 'Chat normal' }}</p>
                    </div>
                    <div class="bg-white px-4 py-3">
                        <p class="text-xs font-black uppercase tracking-[0.12em] text-slate-500">Modelo</p>
                        <p class="mt-1 break-words text-sm font-black text-slate-950">{{ config('services.openrouter.model') }}</p>
                    </div>
                    <div class="grid grid-cols-2 gap-px">
                        <div class="bg-white px-4 py-3">
                            <p class="text-xs font-black uppercase tracking-[0.12em] text-slate-500">Mensajes</p>
                            <p class="mt-1 text-2xl font-black text-slate-950">{{ $this->visibleMessageCount }}</p>
                        </div>
                        <div class="bg-white px-4 py-3">
                            <p class="text-xs font-black uppercase tracking-[0.12em] text-slate-500">Pendientes</p>
                            <p class="mt-1 text-2xl font-black text-amber-700">{{ count($pendingConfirmations) }}</p>
                        </div>
                    </div>
                </div>
            </section>

            @if ($lastLoopSteps !== [])
                <section class="rounded-lg border border-slate-200 bg-white p-4 shadow-sm">
                    <div class="flex items-center justify-between gap-3">
                        <h2 class="text-sm font-black text-slate-950">Ejecucion del loop</h2>
                        <span class="rounded-md bg-emerald-50 px-2 py-1 text-xs font-black text-emerald-700">{{ count($lastLoopSteps) }} pasos</span>
                    </div>
                    <div class="mt-4 space-y-3">
                        @foreach ($lastLoopSteps as $loopIndex => $step)
                            <div wire:key="loop-step-{{ $loopIndex }}" class="border-l-2 border-slate-300 pl-3">
                                <div class="flex items-center justify-between gap-3">
                                    <p class="text-xs font-black uppercase tracking-[0.12em] text-slate-500">
                                        {{ $step['tipo'] ?? 'paso' }} {{ $loopIndex + 1 }}
                                    </p>
                                    <span @class([
                                        'rounded-full px-2 py-1 text-xs font-black',
                                        'bg-emerald-100 text-emerald-800' => ($step['estado'] ?? '') === 'completed',
                                        'bg-amber-100 text-amber-800' => ($step['estado'] ?? '') === 'waiting_confirmation',
                                        'bg-red-100 text-red-800' => ($step['estado'] ?? '') === 'blocked',
                                        'bg-slate-200 text-slate-700' => ! in_array(($step['estado'] ?? ''), ['completed', 'waiting_confirmation', 'blocked'], true),
                                    ])>
                                        {{ $step['estado'] ?? 'running' }}
                                    </span>
                                </div>
                                <p class="mt-2 line-clamp-4 text-xs leading-5 text-slate-600">
                                    {{ $step['resumen'] ?? '' }}
                                </p>
                            </div>
                        @endforeach
                    </div>
                </section>
            @endif

            <section class="rounded-lg border border-slate-200 bg-white p-4 shadow-sm">
                <h2 class="text-sm font-black text-slate-950">Comandos rapidos</h2>
                <div class="mt-3 grid gap-2">
                    @foreach ([
                        'Que inventario esta bajo?',
                        'Busca productos de cono',
                        'Dame el resumen de caja',
                        'Prepara abrir caja con 500 pesos',
                        'Da de alta azucar morena con 5 kg, minimo 1 kg y costo 22 pesos',
                    ] as $example)
                        <button
                            type="button"
                            wire:key="assistant-shortcut-{{ md5($example) }}"
                            wire:click="usarEjemplo(@js($example))"
                            class="rounded-md border border-slate-200 bg-white px-3 py-2 text-left text-sm font-semibold leading-5 text-slate-700 transition hover:border-emerald-300 hover:bg-emerald-50"
                        >
                            {{ $example }}
                        </button>
                    @endforeach
                </div>
            </section>

            <section class="rounded-lg border border-slate-200 bg-white p-4 shadow-sm">
                <h2 class="text-sm font-black text-slate-950">Herramientas</h2>
                <div class="mt-3 grid gap-2 text-sm text-slate-700">
                    @foreach (['Inventario y alertas', 'Productos, precios y recetas', 'Categorias e insumos', 'Caja abierta y cierre', 'Ventas con confirmacion'] as $toolLabel)
                        <div wire:key="tool-label-{{ $toolLabel }}" class="rounded-md border border-slate-200 bg-slate-50 px-3 py-2 font-semibold">
                            {{ $toolLabel }}
                        </div>
                    @endforeach
                </div>
            </section>

            <section class="rounded-lg border border-slate-200 bg-white p-4 shadow-sm">
                <h2 class="text-sm font-black text-slate-950">Ultimas tools</h2>
                @if ($lastToolResults === [])
                    <p class="mt-3 text-sm leading-6 text-slate-500">
                        Cuando el modelo use una tool, aqui veras el nombre y el estado devuelto.
                    </p>
                @else
                    <div class="mt-3 space-y-2">
                        @foreach ($lastToolResults as $toolIndex => $tool)
                            <div wire:key="tool-result-{{ $toolIndex }}" class="rounded-md border border-slate-200 bg-slate-50 px-3 py-2">
                                <p class="text-sm font-bold text-slate-900">{{ $tool['name'] }}</p>
                                <p class="mt-1 text-xs font-semibold text-slate-500">
                                    {{ data_get($tool, 'result.status', data_get($tool, 'result.operacion', 'consulta')) }}
                                </p>
                            </div>
                        @endforeach
                    </div>
                @endif
            </section>
        </aside>
    </div>
</div>

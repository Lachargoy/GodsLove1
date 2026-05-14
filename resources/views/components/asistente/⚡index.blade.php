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

    /**
     * @var array<int, array<string, mixed>>
     */
    public array $lastToolResults = [];

    /**
     * @var array<int, array<string, mixed>>
     */
    public array $pendingConfirmations = [];

    public function mount(): void
    {
        if ($this->messages === []) {
            $this->messages = [
                [
                    'role' => 'assistant',
                    'content' => 'Listo. Puedo consultar inventario, buscar productos, preparar ventas, abrir/cerrar caja y preparar movimientos de inventario. Para modificar datos siempre te voy a pedir confirmacion.',
                ],
            ];
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
            $response = $assistant->respond($this->messages, auth()->user(), $this->pendingConfirmations);
            $this->messages = $response['messages'];
            $this->lastToolResults = $response['tool_results'];
            $this->pendingConfirmations = $response['pending_confirmations'];
        } catch (\Throwable $throwable) {
            $this->error = $throwable->getMessage();
        }
    }

    public function limpiar(): void
    {
        $this->reset(['prompt', 'error', 'lastToolResults', 'pendingConfirmations']);
        $this->messages = [
            [
                'role' => 'assistant',
                'content' => 'Conversacion limpia. Dime que operacion quieres revisar o preparar.',
            ],
        ];
    }

    public function usarEjemplo(string $message): void
    {
        $this->prompt = $message;
    }
};
?>

<div class="mx-auto flex w-full max-w-7xl flex-col gap-6 px-4 py-6 sm:px-6 lg:px-8">
    <header class="flex flex-col gap-4 lg:flex-row lg:items-end lg:justify-between">
        <div>
            <p class="text-xs font-bold uppercase tracking-[0.22em] text-emerald-700">Asistente interno</p>
            <h1 class="app-page-title mt-2">Operador IA</h1>
            <p class="app-page-copy mt-2 max-w-3xl">
                Conectado a OpenRouter con herramientas controladas del sistema. Consulta primero, prepara cambios y confirma solo cuando tu lo autorices.
            </p>
        </div>

        <button type="button" wire:click="limpiar" class="app-secondary-button self-start lg:self-auto">
            Limpiar chat
        </button>
    </header>

    @if (! config('services.openrouter.key'))
        <div class="rounded-2xl border border-amber-200 bg-amber-50 px-4 py-3 text-sm font-medium text-amber-900">
            Falta OPENROUTER_API_KEY en el .env. La interfaz ya esta lista, pero no podra llamar al modelo hasta configurar la llave.
        </div>
    @endif

    @if ($error)
        <div class="rounded-2xl border border-red-200 bg-red-50 px-4 py-3 text-sm font-medium text-red-800">
            {{ $error }}
        </div>
    @endif

    <div class="grid gap-6 lg:grid-cols-[minmax(0,1fr)_320px]">
        <section class="app-card flex min-h-[640px] flex-col overflow-hidden p-0">
            <div class="flex-1 space-y-4 overflow-y-auto p-5">
                @foreach ($messages as $index => $message)
                    @continue($message['hidden'] ?? false)
                    @continue(($message['role'] ?? '') === 'tool')
                    @continue(($message['content'] ?? '') === null)

                    <div
                        wire:key="assistant-message-{{ $index }}"
                        @class([
                            'flex',
                            'justify-end' => ($message['role'] ?? '') === 'user',
                            'justify-start' => ($message['role'] ?? '') !== 'user',
                        ])
                    >
                        <div
                            @class([
                                'max-w-[82%] rounded-2xl px-4 py-3 text-sm leading-6 shadow-sm',
                                'bg-slate-950 text-white' => ($message['role'] ?? '') === 'user',
                                'border border-slate-200 bg-white text-slate-800' => ($message['role'] ?? '') !== 'user',
                            ])
                        >
                            {!! nl2br(e((string) $message['content'])) !!}
                        </div>
                    </div>
                @endforeach

                <div wire:loading.flex wire:target="enviar" class="justify-start">
                    <div class="rounded-2xl border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm font-medium text-emerald-900">
                        Consultando modelo y herramientas...
                    </div>
                </div>
            </div>

            <form wire:submit="enviar" class="border-t border-slate-200 bg-slate-50 p-4">
                <div class="flex flex-col gap-3 md:flex-row">
                    <textarea
                        wire:model="prompt"
                        rows="3"
                        class="min-h-24 flex-1 resize-none rounded-2xl border-slate-300 text-sm"
                        placeholder="Ej. Vendi 2 conos sencillos en efectivo, prepara la venta"
                    ></textarea>
                    <button type="submit" class="app-primary-button md:w-40" wire:loading.attr="disabled" wire:target="enviar">
                        Enviar
                    </button>
                </div>
            </form>
        </section>

        <aside class="space-y-4">
            <section class="app-card space-y-3">
                <h2 class="text-sm font-black uppercase tracking-[0.18em] text-slate-500">Atajos</h2>
                <div class="space-y-2">
                    @foreach ([
                        'Que inventario esta bajo?',
                        'Busca productos de cono',
                        'Dame el resumen de caja',
                        'Prepara abrir caja con 500 pesos',
                    ] as $example)
                        <button
                            type="button"
                            wire:click="usarEjemplo(@js($example))"
                            class="w-full rounded-xl border border-slate-200 bg-white px-3 py-2 text-left text-sm font-semibold text-slate-700 transition hover:border-emerald-300 hover:bg-emerald-50"
                        >
                            {{ $example }}
                        </button>
                    @endforeach
                </div>
            </section>

            <section class="app-card space-y-3">
                <h2 class="text-sm font-black uppercase tracking-[0.18em] text-slate-500">Herramientas</h2>
                <div class="grid gap-2 text-sm text-slate-700">
                    <div class="rounded-xl bg-slate-50 px-3 py-2">Inventario y alertas</div>
                    <div class="rounded-xl bg-slate-50 px-3 py-2">Productos y precios</div>
                    <div class="rounded-xl bg-slate-50 px-3 py-2">Caja abierta y cierre</div>
                    <div class="rounded-xl bg-slate-50 px-3 py-2">Ventas con confirmacion</div>
                    <div class="rounded-xl bg-slate-50 px-3 py-2">Movimientos de inventario</div>
                </div>
            </section>

            @if ($lastToolResults !== [])
                <section class="app-card space-y-3">
                    <h2 class="text-sm font-black uppercase tracking-[0.18em] text-slate-500">Ultimas tools</h2>
                    <div class="space-y-2">
                        @foreach ($lastToolResults as $toolIndex => $tool)
                            <div wire:key="tool-result-{{ $toolIndex }}" class="rounded-xl border border-slate-200 bg-white px-3 py-2">
                                <p class="text-sm font-bold text-slate-900">{{ $tool['name'] }}</p>
                                <p class="mt-1 text-xs text-slate-500">
                                    {{ data_get($tool, 'result.status', data_get($tool, 'result.operacion', 'consulta')) }}
                                </p>
                            </div>
                        @endforeach
                    </div>
                </section>
            @endif
        </aside>
    </div>
</div>

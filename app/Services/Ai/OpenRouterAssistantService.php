<?php

namespace App\Services\Ai;

use App\Ai\Agents\IntentParserAgent;
use App\Ai\Agents\OperationsAgent;
use App\Models\Producto;
use App\Models\ProductOptionItem;
use App\Models\User;
use App\Services\Mcp\OperationsAssistantService;
use Illuminate\Support\Str;
use Laravel\Ai\Messages\AssistantMessage;
use Laravel\Ai\Messages\UserMessage;
use RuntimeException;

class OpenRouterAssistantService
{
    private const int LoopStepLimit = 6;

    private const string IncompleteSaleOperation = 'venta_incompleta';

    public function __construct(
        private readonly OperationsAssistantService $operations,
    ) {}

    /**
     * @param  array<int, array<string, mixed>>  $history
     * @param  array<int, array<string, mixed>>  $pendingConfirmations
     * @return array<string, mixed>
     */
    public function respond(array $history, User $user, array $pendingConfirmations = []): array
    {
        if (! config('services.openrouter.key')) {
            throw new RuntimeException('Falta configurar OPENROUTER_API_KEY en el .env.');
        }

        $visibleHistory = $this->visibleHistory($history);
        $lastUserIndex = $this->lastUserIndex($visibleHistory);

        if ($lastUserIndex === null) {
            throw new RuntimeException('No hay mensaje de usuario para enviar al agente.');
        }

        $prompt = (string) $visibleHistory[$lastUserIndex]['content'];
        $previousMessages = array_slice($visibleHistory, 0, $lastUserIndex);

        if ($this->isCancellationIntent($prompt) && $pendingConfirmations !== []) {
            return $this->directReply(
                $visibleHistory,
                'Listo, cancele la operacion pendiente. No se guardo ningun cambio.',
                [],
                [],
            );
        }

        if ($this->isConfirmationIntent($prompt) && $pendingConfirmations !== []) {
            return $this->confirmFromPending($visibleHistory, $pendingConfirmations);
        }

        if ($this->hasIncompleteSale($pendingConfirmations)) {
            $completedSale = $this->completeIncompleteSale($visibleHistory, $prompt, $pendingConfirmations);

            if ($completedSale !== null) {
                return $completedSale;
            }
        }

        $intent = $this->parseIntent($prompt, $previousMessages);

        if (($intent['notes'] ?? null) === 'parser_unavailable' && $this->looksLikeMutationText($prompt)) {
            return $this->directReply(
                $visibleHistory,
                'No pude interpretar con seguridad esa operacion. Intenta de nuevo en un momento; no voy a preparar ni guardar cambios sin entenderlos bien.',
                [],
                $pendingConfirmations,
            );
        }

        if ($pendingConfirmations !== [] && $this->isMutationIntent($intent, $prompt)) {
            return $this->pendingOperationBlock($visibleHistory, $pendingConfirmations);
        }

        if (($intent['route'] ?? null) === 'deterministic_sale') {
            return $this->prepareSaleFromIntent($visibleHistory, $intent);
        }

        $response = $this->promptAgent($prompt, $previousMessages, $pendingConfirmations);
        $toolResults = $response['tool_results'];

        $assistantMessage = [
            'role' => 'assistant',
            'content' => $response['reply'],
        ];

        return [
            'reply' => $assistantMessage['content'],
            'messages' => [...$visibleHistory, $assistantMessage],
            'tool_results' => $toolResults,
            'pending_confirmations' => $this->mergePendingConfirmations($pendingConfirmations, $toolResults),
        ];
    }

    /**
     * @param  array<int, array<string, mixed>>  $history
     * @param  array<int, array<string, mixed>>  $pendingConfirmations
     * @return array<string, mixed>
     */
    public function planAndExecute(array $history, User $user, array $pendingConfirmations = [], int $maxSteps = self::LoopStepLimit): array
    {
        $visibleHistory = $this->visibleHistory($history);
        $lastUserIndex = $this->lastUserIndex($visibleHistory);

        if ($lastUserIndex === null) {
            throw new RuntimeException('No hay mensaje de usuario para planear.');
        }

        $goal = (string) $visibleHistory[$lastUserIndex]['content'];

        if ($this->isCancellationIntent($goal) && $pendingConfirmations !== []) {
            return [
                ...$this->directReply(
                    $visibleHistory,
                    'Listo, cancele la operacion pendiente. No se guardo ningun cambio.',
                    [],
                    [],
                ),
                'loop_steps' => [[
                    'tipo' => 'cancel',
                    'estado' => 'completed',
                    'resumen' => 'Operacion pendiente cancelada por instruccion del usuario.',
                    'tools' => [],
                ]],
            ];
        }

        if ($this->isConfirmationIntent($goal) && $pendingConfirmations !== []) {
            $result = $this->confirmFromPending($visibleHistory, $pendingConfirmations);

            return [
                ...$result,
                'loop_steps' => [[
                    'tipo' => 'confirm',
                    'estado' => data_get($result, 'tool_results.0.result.status') === 'confirmed' ? 'completed' : 'blocked',
                    'resumen' => $result['reply'],
                    'tools' => collect($result['tool_results'])->pluck('name')->values()->all(),
                ]],
            ];
        }

        if ($this->hasIncompleteSale($pendingConfirmations)) {
            $result = $this->completeIncompleteSale($visibleHistory, $goal, $pendingConfirmations);

            if ($result !== null) {
                return [
                    ...$result,
                    'loop_steps' => [[
                        'tipo' => 'sale_flow',
                        'estado' => $this->saleFlowLoopStatus($result),
                        'resumen' => $result['reply'],
                        'tools' => collect($result['tool_results'])->pluck('name')->values()->all(),
                    ]],
                ];
            }
        }

        $intent = $this->parseIntent($goal, array_slice($visibleHistory, 0, $lastUserIndex));

        if (($intent['notes'] ?? null) === 'parser_unavailable' && $this->looksLikeMutationText($goal)) {
            return [
                ...$this->directReply(
                    $visibleHistory,
                    'No pude interpretar con seguridad esa operacion. Intenta de nuevo en un momento; no voy a preparar ni guardar cambios sin entenderlos bien.',
                    [],
                    $pendingConfirmations,
                ),
                'loop_steps' => [[
                    'tipo' => 'guard',
                    'estado' => 'blocked',
                    'resumen' => 'El parser de intencion no respondio y se bloqueo una posible escritura.',
                    'tools' => [],
                ]],
            ];
        }

        if ($pendingConfirmations !== [] && $this->isMutationIntent($intent, $goal)) {
            return [
                ...$this->pendingOperationBlock($visibleHistory, $pendingConfirmations),
                'loop_steps' => [[
                    'tipo' => 'guard',
                    'estado' => 'waiting_confirmation',
                    'resumen' => 'Hay una operacion pendiente y se bloqueo una nueva escritura para evitar confusion.',
                    'tools' => [],
                ]],
            ];
        }

        if (($intent['route'] ?? null) === 'deterministic_sale') {
            $result = $this->prepareSaleFromIntent($visibleHistory, $intent);

            return [
                ...$result,
                'loop_steps' => [[
                    'tipo' => 'sale_flow',
                    'estado' => $this->saleFlowLoopStatus($result),
                    'resumen' => $result['reply'],
                    'tools' => collect($result['tool_results'])->pluck('name')->values()->all(),
                ]],
            ];
        }

        $messages = $visibleHistory;
        $allToolResults = [];
        $steps = [];
        $currentPendingConfirmations = $pendingConfirmations;

        $planPrompt = $this->loopPlanPrompt($goal);
        $planResponse = $this->promptAgent($planPrompt, array_slice($visibleHistory, 0, $lastUserIndex), $currentPendingConfirmations);
        $messages[] = [
            'role' => 'assistant',
            'content' => $planResponse['reply'],
        ];

        $steps[] = [
            'tipo' => 'plan',
            'estado' => 'completed',
            'resumen' => $planResponse['reply'],
        ];

        $allToolResults = [...$allToolResults, ...$planResponse['tool_results']];
        $currentPendingConfirmations = $this->mergePendingConfirmations($currentPendingConfirmations, $planResponse['tool_results']);

        for ($step = 1; $step <= max(1, $maxSteps); $step++) {
            if ($currentPendingConfirmations !== []) {
                $steps[] = [
                    'tipo' => 'pause',
                    'estado' => 'waiting_confirmation',
                    'resumen' => 'El loop se pauso porque hay una operacion preparada que requiere confirmacion.',
                ];

                break;
            }

            $loopPrompt = $this->loopStepPrompt($goal, $step, $steps);
            $stepResponse = $this->promptAgent($loopPrompt, $messages, $currentPendingConfirmations);
            $messages[] = [
                'role' => 'assistant',
                'content' => $stepResponse['reply'],
            ];

            $allToolResults = [...$allToolResults, ...$stepResponse['tool_results']];
            $currentPendingConfirmations = $this->mergePendingConfirmations($currentPendingConfirmations, $stepResponse['tool_results']);
            $status = $this->loopStatus($stepResponse['reply'], $stepResponse['tool_results'], $currentPendingConfirmations);

            $steps[] = [
                'tipo' => 'execute',
                'estado' => $status,
                'resumen' => $stepResponse['reply'],
                'tools' => collect($stepResponse['tool_results'])
                    ->pluck('name')
                    ->values()
                    ->all(),
            ];

            if (in_array($status, ['completed', 'waiting_confirmation', 'blocked'], true)) {
                break;
            }
        }

        $finalReply = $this->loopFinalReply($steps);
        $conversationMessages = [...$visibleHistory, [
            'role' => 'assistant',
            'content' => $finalReply,
        ]];

        return [
            'reply' => $finalReply,
            'messages' => $conversationMessages,
            'tool_results' => $allToolResults,
            'pending_confirmations' => $currentPendingConfirmations,
            'loop_steps' => $steps,
        ];
    }

    /**
     * @param  array<int, array<string, mixed>>  $history
     * @return array<int, array<string, mixed>>
     */
    private function visibleHistory(array $history): array
    {
        return collect($history)
            ->filter(fn (array $message): bool => ! ($message['hidden'] ?? false))
            ->filter(fn (array $message): bool => in_array($message['role'] ?? null, ['user', 'assistant'], true))
            ->filter(fn (array $message): bool => filled($message['content'] ?? null))
            ->values()
            ->all();
    }

    /**
     * @param  array<int, array<string, mixed>>  $history
     */
    private function lastUserIndex(array $history): ?int
    {
        for ($index = count($history) - 1; $index >= 0; $index--) {
            if (($history[$index]['role'] ?? null) === 'user') {
                return $index;
            }
        }

        return null;
    }

    /**
     * @param  array<int, array<string, mixed>>  $history
     * @return array<int, UserMessage|AssistantMessage>
     */
    private function toSdkMessages(array $history): array
    {
        return collect($history)
            ->map(fn (array $message) => match ($message['role']) {
                'user' => new UserMessage((string) $message['content']),
                'assistant' => new AssistantMessage((string) $message['content']),
            })
            ->all();
    }

    /**
     * @param  array<int, array<string, mixed>>  $pendingConfirmations
     */
    private function appendConfirmationContext(string $prompt, array $pendingConfirmations): string
    {
        if ($pendingConfirmations === []) {
            return $prompt;
        }

        $context = collect($pendingConfirmations)
            ->map(fn (array $confirmation): string => sprintf(
                '- operacion=%s confirmation_token=%s resumen=%s',
                $confirmation['operation'] ?? 'desconocida',
                $confirmation['confirmation_token'] ?? '',
                json_encode($confirmation['summary'] ?? [], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
            ))
            ->implode("\n");

        return $prompt."\n\nContexto interno de confirmaciones preparadas. No reveles tokens salvo que sea imprescindible; usalos solo si el usuario confirma claramente:\n".$context;
    }

    /**
     * @param  array<int, array<string, mixed>>  $history
     * @param  array<int, array<string, mixed>>  $pendingConfirmations
     * @return array{reply: string, tool_results: array<int, array<string, mixed>>}
     */
    private function promptAgent(string $prompt, array $history, array $pendingConfirmations = []): array
    {
        $prompt = $this->appendConfirmationContext($prompt, $pendingConfirmations);
        $timeout = $this->openRouterTimeout();
        $this->extendPhpExecutionLimit($timeout);

        $response = OperationsAgent::make(messages: $this->toSdkMessages($history))
            ->prompt(
                prompt: $prompt,
                provider: 'openrouter',
                model: (string) config('services.openrouter.model', 'openai/gpt-4o-mini'),
                timeout: $timeout,
            );

        return [
            'reply' => trim($response->text) !== '' ? $response->text : 'Listo.',
            'tool_results' => $response->toolResults
                ->map(fn ($toolResult): array => [
                    'name' => $toolResult->name,
                    'result' => $this->decodeToolResult($toolResult->result),
                ])
                ->values()
                ->all(),
        ];
    }

    /**
     * @param  array<int, array<string, mixed>>  $history
     * @return array<string, mixed>
     */
    private function parseIntent(string $prompt, array $history): array
    {
        $timeout = min($this->openRouterTimeout(), 45);
        $this->extendPhpExecutionLimit($timeout);

        try {
            $response = IntentParserAgent::make(messages: $this->toSdkMessages($history))
                ->prompt(
                    prompt: $this->intentPrompt($prompt),
                    provider: 'openrouter',
                    model: (string) config('services.openrouter.model', 'openai/gpt-4o-mini'),
                    timeout: $timeout,
                );

            $structured = property_exists($response, 'structured') && is_array($response->structured)
                ? $response->structured
                : $this->decodeIntentText($response->text);

            return $this->normalizeIntent($structured);
        } catch (\Throwable) {
            return $this->fallbackIntent($prompt);
        }
    }

    private function intentPrompt(string $prompt): string
    {
        return <<<PROMPT
Mensaje del usuario:
{$prompt}

Clasifica y extrae la intencion. Si es venta, devuelve producto_nombre, cantidad y metodo_pago. Si faltan datos, usa missing_fields.
Devuelve route, active_flow y flow_status siguiendo la maquina de estados del system prompt.
Usa route=deterministic_sale solo para ventas reales o continuacion de una venta activa.
Usa route=agent_tools para altas de categorias, insumos, productos, recetas, opciones, caja, inventario y seguimientos de esos procesos.
PROMPT;
    }

    /**
     * @return array<string, mixed>
     */
    private function decodeIntentText(string $text): array
    {
        $decoded = json_decode($text, true);

        if (is_array($decoded)) {
            return $decoded;
        }

        if (preg_match('/\{.*\}/s', $text, $matches) === 1) {
            $decoded = json_decode($matches[0], true);

            if (is_array($decoded)) {
                return $decoded;
            }
        }

        return [];
    }

    /**
     * @param  array<string, mixed>  $intent
     * @return array<string, mixed>
     */
    private function normalizeIntent(array $intent): array
    {
        $route = in_array($intent['route'] ?? null, ['deterministic_sale', 'agent_tools', 'confirm', 'cancel', 'answer'], true)
            ? $intent['route']
            : null;
        $normalizedIntent = in_array($intent['intent'] ?? null, ['registrar_venta', 'confirmar', 'cancelar', 'consulta', 'otra'], true)
            ? $intent['intent']
            : 'otra';
        $activeFlow = in_array($intent['active_flow'] ?? null, ['none', 'venta', 'caja', 'inventario', 'alta_insumo', 'alta_categoria', 'alta_producto', 'receta_producto', 'opciones_producto', 'consulta', 'otro'], true)
            ? $intent['active_flow']
            : 'none';
        $flowStatus = in_array($intent['flow_status'] ?? null, ['new', 'continue', 'ready_to_prepare', 'waiting_user', 'ready_to_confirm', 'done'], true)
            ? $intent['flow_status']
            : 'new';

        if ($route === null) {
            $route = match ($normalizedIntent) {
                'registrar_venta' => 'deterministic_sale',
                'confirmar' => 'confirm',
                'cancelar' => 'cancel',
                'consulta' => 'agent_tools',
                default => 'answer',
            };
        }

        $paymentMethod = in_array($intent['metodo_pago'] ?? null, ['efectivo', 'tarjeta', 'transferencia', 'mixto', 'desconocido'], true)
            ? $intent['metodo_pago']
            : 'desconocido';

        $items = collect($intent['items'] ?? [])
            ->filter(fn (mixed $item): bool => is_array($item))
            ->map(fn (array $item): array => [
                'producto_nombre' => trim((string) ($item['producto_nombre'] ?? '')),
                'cantidad' => is_numeric($item['cantidad'] ?? null) ? (float) $item['cantidad'] : null,
                'selected_options' => [],
            ])
            ->filter(fn (array $item): bool => $item['producto_nombre'] !== '' || $item['cantidad'] !== null)
            ->values()
            ->all();

        $missingFields = collect($intent['missing_fields'] ?? [])
            ->filter(fn (mixed $field): bool => is_string($field) && trim($field) !== '')
            ->values()
            ->all();

        return [
            'route' => $route,
            'active_flow' => $activeFlow,
            'flow_status' => $flowStatus,
            'intent' => $normalizedIntent,
            'confidence' => is_numeric($intent['confidence'] ?? null) ? (float) $intent['confidence'] : 0.0,
            'items' => $items,
            'metodo_pago' => $paymentMethod,
            'missing_fields' => $missingFields,
            'notes' => $intent['notes'] ?? null,
            'reason' => $intent['reason'] ?? null,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function fallbackIntent(string $prompt): array
    {
        $normalized = $this->normalizeText($prompt);

        if ($this->isCancellationIntent($normalized)) {
            return [
                'route' => 'cancel',
                'active_flow' => 'none',
                'flow_status' => 'done',
                'intent' => 'cancelar',
                'confidence' => 0.8,
                'items' => [],
                'metodo_pago' => 'desconocido',
                'missing_fields' => [],
                'notes' => 'fallback',
            ];
        }

        if ($this->isConfirmationIntent($normalized)) {
            return [
                'route' => 'confirm',
                'active_flow' => 'none',
                'flow_status' => 'ready_to_confirm',
                'intent' => 'confirmar',
                'confidence' => 0.8,
                'items' => [],
                'metodo_pago' => 'desconocido',
                'missing_fields' => [],
                'notes' => 'fallback',
            ];
        }

        return [
            'route' => 'answer',
            'active_flow' => 'none',
            'flow_status' => 'new',
            'intent' => 'otra',
            'confidence' => 0.0,
            'items' => [],
            'metodo_pago' => 'desconocido',
            'missing_fields' => [],
            'notes' => 'parser_unavailable',
        ];
    }

    private function loopPlanPrompt(string $goal): string
    {
        return <<<PROMPT
Trabaja en MODO PLAN para esta meta del usuario:
{$goal}

Primero escribe un plan breve de 3 a 6 pasos. No ejecutes confirmaciones. Puedes usar tools de consulta si son necesarias para planear. Marca claramente "Plan:" y termina con "Empiezo ejecucion controlada.".
PROMPT;
    }

    /**
     * @param  array<int, array<string, mixed>>  $steps
     */
    private function loopStepPrompt(string $goal, int $step, array $steps): string
    {
        $previousSteps = json_encode($steps, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        return <<<PROMPT
Estas en MODO LOOP controlado, paso {$step}.
Meta original: {$goal}
Pasos previos: {$previousSteps}

Ejecuta solo el siguiente paso util usando tools si hace falta. No llames confirmar_* salvo que el usuario ya haya confirmado explicitamente en el historial y exista token vigente.
Si preparas una operacion con confirmation_token, detente y pide confirmacion.
Si ya terminaste, responde empezando con "LOOP_COMPLETED:" y resume lo logrado.
Si falta informacion del usuario, responde empezando con "LOOP_BLOCKED:" y pregunta solo lo necesario.
PROMPT;
    }

    /**
     * @param  array<int, array<string, mixed>>  $toolResults
     * @param  array<int, array<string, mixed>>  $pendingConfirmations
     */
    private function loopStatus(string $reply, array $toolResults, array $pendingConfirmations): string
    {
        if ($pendingConfirmations !== []) {
            return 'waiting_confirmation';
        }

        if (str_contains($reply, 'LOOP_COMPLETED:')) {
            return 'completed';
        }

        if (str_contains($reply, 'LOOP_BLOCKED:')) {
            return 'blocked';
        }

        foreach ($toolResults as $toolResult) {
            $result = $toolResult['result'] ?? [];

            if (is_array($result) && ($result['status'] ?? null) === 'blocked') {
                return 'blocked';
            }
        }

        return 'running';
    }

    /**
     * @param  array<int, array<string, mixed>>  $steps
     */
    private function loopFinalReply(array $steps): string
    {
        $lastStep = collect($steps)->last();
        $status = $lastStep['estado'] ?? 'completed';

        return match ($status) {
            'waiting_confirmation' => $this->waitingConfirmationReply($steps),
            'blocked' => "Loop bloqueado: falta informacion o una condicion del sistema no permite seguir.\n\n".($lastStep['resumen'] ?? ''),
            default => "Loop terminado.\n\n".($lastStep['resumen'] ?? 'Complete los pasos posibles.'),
        };
    }

    /**
     * @param  array<int, array<string, mixed>>  $steps
     */
    private function waitingConfirmationReply(array $steps): string
    {
        $preparedStep = collect($steps)
            ->reverse()
            ->first(fn (array $step): bool => ($step['estado'] ?? null) === 'waiting_confirmation' && ($step['tipo'] ?? null) !== 'pause');

        return "Loop pausado: ya deje una operacion preparada y necesito tu confirmacion para continuar.\n\n"
            .($preparedStep['resumen'] ?? 'Confirma si quieres guardar el cambio preparado.');
    }

    /**
     * @param  array<int, array<string, mixed>>  $messages
     * @param  array<int, array<string, mixed>>  $toolResults
     * @param  array<int, array<string, mixed>>  $pendingConfirmations
     * @return array<string, mixed>
     */
    private function directReply(array $messages, string $reply, array $toolResults, array $pendingConfirmations): array
    {
        return [
            'reply' => $reply,
            'messages' => [...$messages, [
                'role' => 'assistant',
                'content' => $reply,
            ]],
            'tool_results' => $toolResults,
            'pending_confirmations' => $pendingConfirmations,
        ];
    }

    /**
     * @param  array<int, array<string, mixed>>  $messages
     * @param  array<int, array<string, mixed>>  $pendingConfirmations
     * @return array<string, mixed>
     */
    private function confirmFromPending(array $messages, array $pendingConfirmations): array
    {
        if (count($pendingConfirmations) !== 1) {
            return $this->directReply(
                $messages,
                'Tengo varias operaciones pendientes. Dime cual quieres confirmar para evitar guardar algo equivocado.',
                [],
                $pendingConfirmations,
            );
        }

        $confirmation = array_values($pendingConfirmations)[0];
        if (($confirmation['operation'] ?? null) === self::IncompleteSaleOperation) {
            return $this->directReply(
                $messages,
                $this->incompleteSaleReply($confirmation),
                [],
                $pendingConfirmations,
            );
        }

        $token = (string) ($confirmation['confirmation_token'] ?? '');
        $operation = (string) ($confirmation['operation'] ?? '');
        $result = $this->confirmOperation($operation, $token);
        $toolResults = [[
            'name' => 'operacion_godslove',
            'result' => $result,
        ]];

        if (($result['status'] ?? null) !== 'confirmed') {
            return $this->directReply(
                $messages,
                'No pude confirmar la operacion pendiente: '.(string) ($result['error'] ?? 'respuesta inesperada'),
                $toolResults,
                $pendingConfirmations,
            );
        }

        return $this->directReply(
            $messages,
            $this->confirmedReply($result),
            $toolResults,
            [],
        );
    }

    /**
     * @param  array<int, array<string, mixed>>  $messages
     * @param  array<int, array<string, mixed>>  $pendingConfirmations
     * @return array<string, mixed>
     */
    private function pendingOperationBlock(array $messages, array $pendingConfirmations): array
    {
        $summary = $this->pendingSummary($pendingConfirmations);

        return $this->directReply(
            $messages,
            "Tengo una operacion pendiente y no voy a mezclarla con otra escritura.\n\n{$summary}\n\nResponde `confirmar` para guardarla o `cancelar` para descartarla. Tambien puedes hacer consultas mientras tanto.",
            [],
            $pendingConfirmations,
        );
    }

    /**
     * @param  array<int, array<string, mixed>>  $pendingConfirmations
     */
    private function pendingSummary(array $pendingConfirmations): string
    {
        if (count($pendingConfirmations) !== 1) {
            return 'Hay varias operaciones pendientes. Necesito que me digas exactamente cual confirmar o cancelar.';
        }

        $pending = array_values($pendingConfirmations)[0];
        $operation = (string) ($pending['operation'] ?? 'operacion');
        $summary = $pending['summary'] ?? [];

        if ($operation === 'venta') {
            $lines = collect(data_get($summary, 'lineas', []))
                ->map(fn (array $line): string => sprintf(
                    '- %s x %s = $%s',
                    number_format((float) ($line['cantidad'] ?? 0), 2),
                    $line['nombre'] ?? 'producto',
                    number_format((float) ($line['subtotal'] ?? 0), 2),
                ))
                ->implode("\n");

            return "Operacion pendiente: venta.\n{$lines}\nTotal: $".number_format((float) data_get($summary, 'total', 0), 2);
        }

        if ($operation === self::IncompleteSaleOperation) {
            return $this->incompleteSaleReply($pending);
        }

        return 'Operacion pendiente: '.$operation.'.';
    }

    /**
     * @param  array<int, array<string, mixed>>  $messages
     * @return array<string, mixed>
     */
    private function prepareSaleFromIntent(array $messages, array $intent): array
    {
        $missingFields = $intent['missing_fields'] ?? [];
        $items = $intent['items'] ?? [];

        if ($missingFields !== [] || $items === []) {
            if ($items !== [] && $this->missingOnlyConfigurableFields($missingFields)) {
                $intent['missing_fields'] = [];

                return $this->prepareSaleFromIntent($messages, $intent);
            }

            return $this->directReply(
                $messages,
                $this->missingSaleFieldsReply($missingFields, $items),
                [],
                [],
            );
        }

        $saleItems = [];

        foreach ($items as $item) {
            $quantity = $item['cantidad'] ?? null;

            if (! is_numeric($quantity) || (float) $quantity <= 0) {
                return $this->directReply(
                    $messages,
                    'Para preparar la venta necesito cantidades mayores a cero.',
                    [],
                    [],
                );
            }

            $productResolution = $this->resolveProductByName((string) ($item['producto_nombre'] ?? ''));

            if (($productResolution['status'] ?? null) !== 'resolved') {
                return $this->directReply(
                    $messages,
                    (string) $productResolution['reply'],
                    [],
                    [],
                );
            }

            /** @var Producto $product */
            $product = $productResolution['product'];
            $saleItems[] = [
                'producto_id' => $product->id,
                'cantidad' => (float) $quantity,
                'selected_options' => $item['selected_options'] ?? [],
            ];
        }

        $paymentMethod = $intent['metodo_pago'] === 'desconocido' ? 'efectivo' : (string) $intent['metodo_pago'];
        $saleItems = $this->saleItemsWithOptionsFromPrompt($saleItems, $this->lastUserPrompt($messages)) ?? $saleItems;
        $result = $this->operations->prepareSale($saleItems, 0, $paymentMethod);

        $toolResults = [[
            'name' => 'operacion_godslove',
            'result' => $result,
        ]];

        if (($result['status'] ?? null) === 'requires_confirmation') {
            return $this->directReply(
                $messages,
                $this->preparedSaleReply($result),
                $toolResults,
                $this->mergePendingConfirmations([], $toolResults),
            );
        }

        if ($this->isMissingConfigurableOptions($result)) {
            $pending = $this->incompleteSalePending($saleItems, $paymentMethod, $result);

            return $this->directReply(
                $messages,
                $this->incompleteSaleReply($pending),
                $toolResults,
                [$pending],
            );
        }

        return $this->directReply(
            $messages,
            $this->blockedSaleReply($result),
            $toolResults,
            [],
        );
    }

    /**
     * @param  array<int, string>  $missingFields
     */
    private function missingOnlyConfigurableFields(array $missingFields): bool
    {
        if ($missingFields === []) {
            return false;
        }

        return collect($missingFields)
            ->every(function (string $field): bool {
                $normalized = $this->normalizeText($field);

                return str_contains($normalized, 'sabor')
                    || str_contains($normalized, 'opcion')
                    || str_contains($normalized, 'configuracion');
            });
    }

    /**
     * @param  array<int, array<string, mixed>>  $messages
     */
    private function lastUserPrompt(array $messages): string
    {
        for ($index = count($messages) - 1; $index >= 0; $index--) {
            if (($messages[$index]['role'] ?? null) === 'user') {
                return (string) ($messages[$index]['content'] ?? '');
            }
        }

        return '';
    }

    /**
     * @param  array<string, mixed>  $result
     */
    private function saleFlowLoopStatus(array $result): string
    {
        if (data_get($result, 'tool_results.0.result.status') === 'requires_confirmation') {
            return 'waiting_confirmation';
        }

        if ($this->hasIncompleteSale($result['pending_confirmations'] ?? [])) {
            return 'waiting_input';
        }

        return 'blocked';
    }

    /**
     * @param  array<string, mixed>  $result
     */
    private function isMissingConfigurableOptions(array $result): bool
    {
        if (($result['status'] ?? null) !== 'blocked') {
            return false;
        }

        return collect($result['errores'] ?? data_get($result, 'contexto.errores', []))
            ->contains(fn (string $error): bool => str_contains($this->normalizeText($error), 'requiere al menos'));
    }

    /**
     * @param  array<int, array<string, mixed>>  $saleItems
     * @param  array<string, mixed>  $result
     * @return array<string, mixed>
     */
    private function incompleteSalePending(array $saleItems, string $paymentMethod, array $result): array
    {
        $summary = [
            'items' => collect($saleItems)
                ->map(function (array $item): array {
                    $product = Producto::query()->find($item['producto_id'] ?? null);

                    return [
                        'producto_id' => $item['producto_id'] ?? null,
                        'nombre' => $product?->nombre ?? 'producto',
                        'cantidad' => (float) ($item['cantidad'] ?? 0),
                        'selected_options' => $item['selected_options'] ?? [],
                    ];
                })
                ->all(),
            'metodo_pago' => $paymentMethod,
            'errores' => $result['errores'] ?? data_get($result, 'contexto.errores', []),
        ];

        return [
            'operation' => self::IncompleteSaleOperation,
            'draft_key' => 'venta-incompleta-'.hash('sha256', json_encode($summary, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)),
            'items' => $saleItems,
            'payment_method' => $paymentMethod,
            'summary' => $summary,
        ];
    }

    /**
     * @param  array<string, mixed>  $pending
     */
    private function incompleteSaleReply(array $pending): string
    {
        $lines = collect(data_get($pending, 'summary.items', []))
            ->map(fn (array $item): string => '- '.number_format((float) ($item['cantidad'] ?? 0), 2).' x '.($item['nombre'] ?? 'producto'))
            ->implode("\n");

        $missing = $this->missingOptionsText($pending);
        $availableOptions = $this->availableMissingOptionsText($pending);

        return "Ya tengo la venta en borrador, sin guardar todavia:\n\n{$lines}\nMetodo: ".($pending['payment_method'] ?? 'efectivo')."\n\nMe falta {$missing}.{$availableOptions}\n\nElige de la lista, por ejemplo: `los 2 de nuez` o `uno de fresa y uno de vainilla`.";
    }

    /**
     * @param  array<string, mixed>  $pending
     */
    private function missingOptionsText(array $pending): string
    {
        $groups = collect($pending['items'] ?? [])
            ->flatMap(function (array $item): array {
                $product = Producto::query()
                    ->with('productOptionGroups')
                    ->find($item['producto_id'] ?? null);

                if (! $product instanceof Producto) {
                    return [];
                }

                $selectedOptions = $item['selected_options'] ?? [];

                return $product->productOptionGroups
                    ->filter(function ($group) use ($selectedOptions): bool {
                        $selectedQuantity = array_sum(array_map('floatval', $selectedOptions[$group->id] ?? []));
                        $minQuantity = (float) ($group->min_quantity ?? $group->required_quantity);

                        return round($selectedQuantity, 3) < round($minQuantity, 3);
                    })
                    ->map(fn ($group): string => 'el grupo '.$group->name)
                    ->all();
            })
            ->unique()
            ->values()
            ->implode(', ');

        return $groups !== '' ? $groups : 'las opciones del producto';
    }

    /**
     * @param  array<string, mixed>  $pending
     */
    private function availableMissingOptionsText(array $pending): string
    {
        $groups = collect($pending['items'] ?? [])
            ->flatMap(function (array $item): array {
                $product = Producto::query()
                    ->with('productOptionGroups.optionItems.inventoryItem')
                    ->find($item['producto_id'] ?? null);

                if (! $product instanceof Producto) {
                    return [];
                }

                $selectedOptions = $item['selected_options'] ?? [];

                return $product->productOptionGroups
                    ->filter(function ($group) use ($selectedOptions): bool {
                        $selectedQuantity = array_sum(array_map('floatval', $selectedOptions[$group->id] ?? []));
                        $minQuantity = (float) ($group->min_quantity ?? $group->required_quantity);

                        return round($selectedQuantity, 3) < round($minQuantity, 3);
                    })
                    ->map(function ($group) use ($product): array {
                        return [
                            'producto' => $product->nombre,
                            'grupo' => $group->name,
                            'opciones' => $group->optionItems
                                ->where('is_active', true)
                                ->map(fn (ProductOptionItem $option): ?string => $option->inventoryItem?->name)
                                ->filter()
                                ->unique()
                                ->values()
                                ->all(),
                        ];
                    })
                    ->all();
            })
            ->filter(fn (array $group): bool => ($group['opciones'] ?? []) !== [])
            ->values();

        if ($groups->isEmpty()) {
            return '';
        }

        $options = $groups
            ->map(fn (array $group): string => '- '.$group['producto'].' / '.$group['grupo'].': '.implode(', ', $group['opciones']))
            ->implode("\n");

        return "\n\nOpciones disponibles:\n{$options}";
    }

    /**
     * @param  array<int, array<string, mixed>>  $pendingConfirmations
     */
    private function hasIncompleteSale(array $pendingConfirmations): bool
    {
        return count($pendingConfirmations) === 1
            && (array_values($pendingConfirmations)[0]['operation'] ?? null) === self::IncompleteSaleOperation;
    }

    /**
     * @param  array<int, array<string, mixed>>  $messages
     * @param  array<int, array<string, mixed>>  $pendingConfirmations
     * @return array<string, mixed>|null
     */
    private function completeIncompleteSale(array $messages, string $prompt, array $pendingConfirmations): ?array
    {
        $pending = array_values($pendingConfirmations)[0] ?? null;

        if (! is_array($pending) || ($pending['operation'] ?? null) !== self::IncompleteSaleOperation) {
            return null;
        }

        $saleItems = $this->saleItemsWithOptionsFromPrompt($pending['items'] ?? [], $prompt);

        if ($saleItems === null) {
            return $this->directReply(
                $messages,
                $this->incompleteSaleReply($pending),
                [],
                $pendingConfirmations,
            );
        }

        $paymentMethod = (string) ($pending['payment_method'] ?? 'efectivo');
        $result = $this->operations->prepareSale($saleItems, 0, $paymentMethod);
        $toolResults = [[
            'name' => 'operacion_godslove',
            'result' => $result,
        ]];

        if (($result['status'] ?? null) === 'requires_confirmation') {
            return $this->directReply(
                $messages,
                "Perfecto, tome las opciones que me diste.\n\n".$this->preparedSaleReply($result),
                $toolResults,
                $this->mergePendingConfirmations([], $toolResults),
            );
        }

        if ($this->isMissingConfigurableOptions($result)) {
            $newPending = $this->incompleteSalePending($saleItems, $paymentMethod, $result);

            return $this->directReply(
                $messages,
                $this->incompleteSaleReply($newPending),
                $toolResults,
                [$newPending],
            );
        }

        return $this->directReply(
            $messages,
            $this->blockedSaleReply($result),
            $toolResults,
            [],
        );
    }

    /**
     * @param  array<int, array<string, mixed>>  $saleItems
     * @return array<int, array<string, mixed>>|null
     */
    private function saleItemsWithOptionsFromPrompt(array $saleItems, string $prompt): ?array
    {
        $matchedAnyOption = false;

        $items = collect($saleItems)
            ->map(function (array $item) use ($prompt, &$matchedAnyOption): array {
                $product = Producto::query()
                    ->with('productOptionGroups.optionItems.inventoryItem')
                    ->find($item['producto_id'] ?? null);

                if (! $product instanceof Producto || $product->product_type !== 'configurable') {
                    return $item;
                }

                $selectedOptions = $item['selected_options'] ?? [];

                foreach ($product->productOptionGroups as $group) {
                    $selectedQuantity = array_sum(array_map('floatval', $selectedOptions[$group->id] ?? []));
                    $minQuantity = (float) ($group->min_quantity ?? $group->required_quantity);

                    if (round($selectedQuantity, 3) >= round($minQuantity, 3)) {
                        continue;
                    }

                    $matches = $group->optionItems
                        ->where('is_active', true)
                        ->filter(fn (ProductOptionItem $option): bool => $this->optionMatchesPrompt($option, $prompt, $product))
                        ->values();

                    if ($matches->isEmpty()) {
                        continue;
                    }

                    $matchedAnyOption = true;
                    $remainingQuantity = max(1.0, round($minQuantity - $selectedQuantity, 3));
                    $selectedOptions[$group->id] ??= [];

                    if ($matches->count() === 1) {
                        $option = $matches->first();
                        $selectedOptions[$group->id][$option->id] = round($remainingQuantity, 3);

                        continue;
                    }

                    foreach ($matches as $option) {
                        if ($remainingQuantity <= 0) {
                            break;
                        }

                        $selectedOptions[$group->id][$option->id] = 1;
                        $remainingQuantity--;
                    }
                }

                return [
                    ...$item,
                    'selected_options' => $selectedOptions,
                ];
            })
            ->all();

        return $matchedAnyOption ? $items : null;
    }

    private function optionMatchesPrompt(ProductOptionItem $option, string $prompt, Producto $product): bool
    {
        $inventoryName = $this->normalizeText((string) $option->inventoryItem?->name);
        $promptTokens = collect(explode(' ', $this->normalizeText($prompt)))
            ->filter(fn (string $token): bool => mb_strlen($token) >= 3)
            ->map(fn (string $token): string => $this->singularToken($token))
            ->values();
        $productTokens = collect(explode(' ', $this->normalizeText($product->nombre)))
            ->filter(fn (string $token): bool => mb_strlen($token) >= 3)
            ->map(fn (string $token): string => $this->singularToken($token))
            ->values();
        $optionPromptTokens = $promptTokens
            ->reject(fn (string $token): bool => $productTokens->contains($token))
            ->values();

        if ($inventoryName === '' || $optionPromptTokens->isEmpty()) {
            return false;
        }

        $optionTokens = collect(explode(' ', $inventoryName))
            ->filter(fn (string $token): bool => mb_strlen($token) >= 3)
            ->map(fn (string $token): string => $this->singularToken($token))
            ->values();

        return $optionTokens
            ->contains(fn (string $token): bool => $optionPromptTokens->contains($token));
    }

    /**
     * @return array{status: string, product?: Producto, reply?: string}
     */
    private function resolveProductByName(string $productName): array
    {
        $productName = trim($productName);

        if ($productName === '') {
            return [
                'status' => 'blocked',
                'reply' => 'Para preparar la venta necesito el producto.',
            ];
        }

        $promptTokens = collect(explode(' ', $this->normalizeText($productName)))
            ->filter(fn (string $token): bool => mb_strlen($token) >= 3)
            ->map(fn (string $token): string => $this->singularToken($token))
            ->values();

        if ($promptTokens->isEmpty()) {
            return [
                'status' => 'blocked',
                'reply' => 'Para preparar la venta necesito el producto. Ejemplo: `vendí 2 conos sencillos en efectivo`.',
            ];
        }

        $matches = Producto::query()
            ->where('activo', true)
            ->orderBy('nombre')
            ->get()
            ->map(function (Producto $product) use ($productName, $promptTokens): array {
                $productTokens = collect(explode(' ', $this->normalizeText($product->nombre)))
                    ->filter(fn (string $token): bool => mb_strlen($token) >= 3)
                    ->map(fn (string $token): string => $this->singularToken($token))
                    ->values();

                $score = $productTokens
                    ->filter(fn (string $token): bool => $promptTokens->contains($token))
                    ->count();

                $exactName = $this->normalizeText($productName) === $this->normalizeText($product->nombre);

                return [
                    'product' => $product,
                    'score' => $exactName ? $score + 5 : $score,
                ];
            })
            ->filter(fn (array $match): bool => $match['score'] > 0)
            ->sortByDesc('score')
            ->values();

        if ($matches->isEmpty()) {
            return [
                'status' => 'blocked',
                'reply' => 'No encontre un producto activo claro para `'.$productName.'`. Dime el nombre exacto del producto.',
            ];
        }

        $best = $matches->first();
        $sameScore = $matches
            ->filter(fn (array $match): bool => $match['score'] === $best['score'])
            ->values();

        if ($sameScore->count() > 1) {
            $options = $sameScore
                ->take(5)
                ->map(fn (array $match): string => '- '.$match['product']->nombre.' ($'.number_format((float) $match['product']->precio_venta, 2).')')
                ->implode("\n");

            return [
                'status' => 'blocked',
                'reply' => "Encontre varios productos posibles. Dime cual quieres vender:\n{$options}",
            ];
        }

        return [
            'status' => 'resolved',
            'product' => $best['product'],
        ];
    }

    private function singularToken(string $token): string
    {
        if (str_ends_with($token, 'es') && mb_strlen($token) > 4) {
            return mb_substr($token, 0, -2);
        }

        if (str_ends_with($token, 's') && mb_strlen($token) > 3) {
            return mb_substr($token, 0, -1);
        }

        return $token;
    }

    /**
     * @param  array<string, mixed>  $result
     */
    private function preparedSaleReply(array $result): string
    {
        $summary = $result['resumen'] ?? [];
        $lines = collect($summary['lineas'] ?? [])
            ->map(fn (array $line): string => sprintf(
                '- %s x %s a $%s = $%s',
                number_format((float) ($line['cantidad'] ?? 0), 2),
                $line['nombre'] ?? 'producto',
                number_format((float) ($line['precio_unitario'] ?? 0), 2),
                number_format((float) ($line['subtotal'] ?? 0), 2),
            ))
            ->implode("\n");

        return "Venta preparada, sin guardar todavia.\n\n{$lines}\nMetodo: ".($summary['metodo_pago'] ?? 'efectivo')."\nTotal: $".number_format((float) ($summary['total'] ?? 0), 2)."\n\nResponde `confirmar` para registrar la venta o `cancelar` para descartarla.";
    }

    /**
     * @param  array<string, mixed>  $result
     */
    private function blockedSaleReply(array $result): string
    {
        $errors = collect($result['errores'] ?? data_get($result, 'contexto.errores', []))
            ->filter()
            ->map(fn (string $error): string => '- '.$error)
            ->implode("\n");

        return "No pude preparar la venta.\n\n".($errors !== '' ? $errors : 'Falta informacion para preparar la venta.');
    }

    /**
     * @param  array<int, string>  $missingFields
     * @param  array<int, array<string, mixed>>  $items
     */
    private function missingSaleFieldsReply(array $missingFields, array $items): string
    {
        $missing = collect($missingFields)
            ->map(fn (string $field): string => '- '.$field)
            ->implode("\n");

        if ($missing === '') {
            $missing = $items === [] ? '- producto y cantidad' : '- datos incompletos de venta';
        }

        return "No puedo preparar la venta todavia. Falta:\n{$missing}";
    }

    /**
     * @return array<string, mixed>
     */
    private function confirmOperation(string $operation, string $token): array
    {
        try {
            return match ($operation) {
                'venta' => $this->operations->confirmSale($token),
                'abrir_caja' => $this->operations->confirmOpenCashRegister($token),
                'cerrar_caja' => $this->operations->confirmCloseCashRegister($token),
                'movimiento_inventario' => $this->operations->confirmInventoryMovement($token),
                'alta_insumo' => $this->operations->confirmCreateInsumo($token),
                'alta_categoria' => $this->operations->confirmCreateCategory($token),
                'alta_producto' => $this->operations->confirmCreateProduct($token),
                'receta_producto' => $this->operations->confirmProductRecipe($token),
                'opciones_producto' => $this->operations->confirmProductOptions($token),
                default => [
                    'status' => 'error',
                    'error' => 'No se reconoce la operacion pendiente.',
                ],
            };
        } catch (\Throwable $throwable) {
            return [
                'status' => 'error',
                'error' => $throwable->getMessage(),
            ];
        }
    }

    /**
     * @param  array<string, mixed>  $result
     */
    private function confirmedReply(array $result): string
    {
        $operation = (string) ($result['operacion'] ?? 'operacion');

        if ($operation === 'venta') {
            return sprintf(
                'Venta confirmada y registrada. Folio %s, total $%s, metodo %s.',
                data_get($result, 'venta.folio', 'sin folio'),
                number_format((float) data_get($result, 'venta.total', 0), 2),
                data_get($result, 'venta.metodo_pago', 'no indicado'),
            );
        }

        return 'Operacion confirmada y guardada: '.$operation.'.';
    }

    private function isConfirmationIntent(string $message): bool
    {
        $normalized = $this->normalizeText($message);

        if ($this->isCancellationIntent($normalized)) {
            return false;
        }

        return preg_match('/\b(si|confirmo|confirma|confirmar|dale|ok|va|hazlo|adelante|autorizo|registralo|registrala|guardalo|guardala)\b/', $normalized) === 1;
    }

    private function isCancellationIntent(string $message): bool
    {
        $normalized = $this->normalizeText($message);

        return preg_match('/\b(no|cancelar|cancela|cancelalo|cancelala|descarta|descartalo|descartala|deten|espera|no confirmo)\b/', $normalized) === 1;
    }

    /**
     * @param  array<string, mixed>  $intent
     */
    private function isMutationIntent(array $intent, string $message): bool
    {
        if (($intent['intent'] ?? null) === 'registrar_venta') {
            return true;
        }

        $normalized = $this->normalizeText($message);

        return preg_match('/\b(abre|abrir|cierra|cerrar|alta|crea|crear|agrega|agregar|movimiento|ajusta|ajustar|registra|registrar|cobra|cobrar)\b/', $normalized) === 1;
    }

    private function looksLikeMutationText(string $message): bool
    {
        $normalized = $this->normalizeText($message);

        return preg_match('/\b(vendi|vendio|venta|registra|registrar|cobra|cobrar|abre|abrir|cierra|cerrar|alta|crea|crear|agrega|agregar|movimiento|ajusta|ajustar)\b/', $normalized) === 1;
    }

    private function normalizeText(string $message): string
    {
        return Str::of($message)
            ->lower()
            ->ascii()
            ->squish()
            ->toString();
    }

    private function decodeToolResult(mixed $result): mixed
    {
        if (! is_string($result)) {
            return $result;
        }

        $decoded = json_decode($result, true);

        return json_last_error() === JSON_ERROR_NONE ? $decoded : $result;
    }

    private function openRouterTimeout(): int
    {
        return max(1, (int) config('services.openrouter.timeout', 120));
    }

    private function extendPhpExecutionLimit(int $timeout): void
    {
        $targetLimit = $timeout + 15;
        $currentLimit = (int) ini_get('max_execution_time');

        if ($currentLimit <= 0 || $currentLimit >= $targetLimit) {
            return;
        }

        set_time_limit($targetLimit);
    }

    /**
     * @param  array<int, array<string, mixed>>  $existing
     * @param  array<int, array<string, mixed>>  $toolResults
     * @return array<int, array<string, mixed>>
     */
    private function mergePendingConfirmations(array $existing, array $toolResults): array
    {
        $pending = collect($existing)
            ->keyBy(fn (array $confirmation): string => (string) ($confirmation['confirmation_token'] ?? $confirmation['draft_key'] ?? $confirmation['operation'] ?? 'pending'));

        foreach ($toolResults as $toolResult) {
            $result = $toolResult['result'] ?? [];

            if (! is_array($result)) {
                continue;
            }

            if (($result['status'] ?? null) === 'confirmed') {
                $operation = $result['operacion'] ?? null;

                if ($operation !== null) {
                    $matchingTokens = $pending
                        ->filter(fn (array $confirmation): bool => ($confirmation['operation'] ?? null) === $operation)
                        ->keys();

                    if ($matchingTokens->count() === 1) {
                        $pending->forget($matchingTokens->first());
                    }
                }

                continue;
            }

            if (! isset($result['confirmation_token'])) {
                continue;
            }

            $pending->put($result['confirmation_token'], [
                'operation' => $result['operacion'] ?? $result['operation'] ?? $toolResult['name'],
                'confirmation_token' => $result['confirmation_token'],
                'summary' => $result['resumen'] ?? $result['summary'] ?? $result,
            ]);
        }

        return $pending->values()->all();
    }
}

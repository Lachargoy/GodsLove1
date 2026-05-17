<?php

namespace App\Services\Ai;

use App\Ai\Agents\OperationsAgent;
use App\Models\Producto;
use App\Models\User;
use App\Services\Mcp\OperationsAssistantService;
use Illuminate\Support\Str;
use Laravel\Ai\Messages\AssistantMessage;
use Laravel\Ai\Messages\UserMessage;
use RuntimeException;

class OpenRouterAssistantService
{
    private const int LoopStepLimit = 6;

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

        if ($pendingConfirmations !== [] && $this->isMutationIntent($prompt)) {
            return $this->pendingOperationBlock($visibleHistory, $pendingConfirmations);
        }

        if ($this->isSaleIntent($prompt)) {
            return $this->prepareSaleFromPrompt($visibleHistory, $prompt);
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

        if ($pendingConfirmations !== [] && $this->isMutationIntent($goal)) {
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

        if ($this->isSaleIntent($goal)) {
            $result = $this->prepareSaleFromPrompt($visibleHistory, $goal);

            return [
                ...$result,
                'loop_steps' => [[
                    'tipo' => 'sale_flow',
                    'estado' => data_get($result, 'tool_results.0.result.status') === 'requires_confirmation' ? 'waiting_confirmation' : 'blocked',
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

        return 'Operacion pendiente: '.$operation.'.';
    }

    /**
     * @param  array<int, array<string, mixed>>  $messages
     * @return array<string, mixed>
     */
    private function prepareSaleFromPrompt(array $messages, string $prompt): array
    {
        $quantity = $this->extractQuantity($prompt);

        if ($quantity === null) {
            return $this->directReply(
                $messages,
                'Para preparar la venta necesito la cantidad. Ejemplo: `vendí 2 conos sencillos en efectivo`.',
                [],
                [],
            );
        }

        $productResolution = $this->resolveProductFromPrompt($prompt);

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
        $paymentMethod = $this->extractPaymentMethod($prompt);
        $result = $this->operations->prepareSale([
            [
                'producto_id' => $product->id,
                'cantidad' => $quantity,
            ],
        ], 0, $paymentMethod);

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

        return $this->directReply(
            $messages,
            $this->blockedSaleReply($result),
            $toolResults,
            [],
        );
    }

    private function extractQuantity(string $prompt): ?float
    {
        $normalized = $this->normalizeText($prompt);

        if (preg_match('/\b(\d+(?:[.,]\d+)?)\b/', $normalized, $matches) === 1) {
            return (float) str_replace(',', '.', $matches[1]);
        }

        $words = [
            'un' => 1,
            'una' => 1,
            'uno' => 1,
            'dos' => 2,
            'tres' => 3,
            'cuatro' => 4,
            'cinco' => 5,
            'seis' => 6,
            'siete' => 7,
            'ocho' => 8,
            'nueve' => 9,
            'diez' => 10,
        ];

        foreach ($words as $word => $quantity) {
            if (preg_match('/\b'.preg_quote($word, '/').'\b/', $normalized) === 1) {
                return (float) $quantity;
            }
        }

        return null;
    }

    /**
     * @return array{status: string, product?: Producto, reply?: string}
     */
    private function resolveProductFromPrompt(string $prompt): array
    {
        $promptTokens = collect(explode(' ', $this->saleSearchText($prompt)))
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
            ->map(function (Producto $product) use ($prompt, $promptTokens): array {
                $productTokens = collect(explode(' ', $this->normalizeText($product->nombre)))
                    ->filter(fn (string $token): bool => mb_strlen($token) >= 3)
                    ->map(fn (string $token): string => $this->singularToken($token))
                    ->values();

                $score = $productTokens
                    ->filter(fn (string $token): bool => $promptTokens->contains($token))
                    ->count();

                $exactName = str_contains($this->normalizeText($prompt), $this->normalizeText($product->nombre));

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
                'reply' => 'No encontre un producto activo claro para esa venta. Dime el nombre exacto del producto.',
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

    private function saleSearchText(string $prompt): string
    {
        return Str::of($prompt)
            ->lower()
            ->ascii()
            ->replaceMatches('/\b(registra|registrar|registre|vendi|vendí|venta|vender|vendio|vendió|cobra|cobrar|cobre|de|del|la|el|los|las|en|por|con|pago|pagaron|cliente|efectivo|tarjeta|transferencia|mixto|pesos|peso|mxn)\b/', ' ')
            ->replaceMatches('/\b\d+(?:[.,]\d+)?\b/', ' ')
            ->squish()
            ->toString();
    }

    private function extractPaymentMethod(string $prompt): string
    {
        $normalized = $this->normalizeText($prompt);

        return match (true) {
            str_contains($normalized, 'tarjeta') => 'tarjeta',
            str_contains($normalized, 'transferencia') => 'transferencia',
            str_contains($normalized, 'mixto') => 'mixto',
            default => 'efectivo',
        };
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

        return preg_match('/\b(si|confirmo|confirma|dale|ok|va|hazlo|adelante|autorizo|registralo|registrala|guardalo|guardala)\b/', $normalized) === 1;
    }

    private function isCancellationIntent(string $message): bool
    {
        $normalized = $this->normalizeText($message);

        return preg_match('/\b(no|cancela|cancelalo|cancelala|deten|espera|no confirmo)\b/', $normalized) === 1;
    }

    private function isSaleIntent(string $message): bool
    {
        $normalized = $this->normalizeText($message);

        return preg_match('/\b(vendi|vendio|venta|registra|registrar|cobra|cobrar)\b/', $normalized) === 1
            && ! preg_match('/\b(caja|insumo|categoria|producto nuevo|alta|inventario|movimiento|cierre|abrir)\b/', $normalized);
    }

    private function isMutationIntent(string $message): bool
    {
        $normalized = $this->normalizeText($message);

        return $this->isSaleIntent($normalized)
            || preg_match('/\b(abre|abrir|cierra|cerrar|alta|crea|crear|agrega|agregar|movimiento|ajusta|ajustar|registra|registrar|cobra|cobrar)\b/', $normalized) === 1;
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
            ->keyBy('confirmation_token');

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

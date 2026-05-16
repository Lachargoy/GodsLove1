<?php

namespace App\Services\Ai;

use App\Ai\Agents\OperationsAgent;
use App\Models\User;
use Laravel\Ai\Messages\AssistantMessage;
use Laravel\Ai\Messages\UserMessage;
use RuntimeException;

class OpenRouterAssistantService
{
    private const int LoopStepLimit = 6;

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

        return [
            'reply' => $finalReply,
            'messages' => [...$messages, [
                'role' => 'assistant',
                'content' => $finalReply,
            ]],
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
            'waiting_confirmation' => "Loop pausado: ya deje una operacion preparada y necesito tu confirmacion para continuar.\n\n".($lastStep['resumen'] ?? ''),
            'blocked' => "Loop bloqueado: falta informacion o una condicion del sistema no permite seguir.\n\n".($lastStep['resumen'] ?? ''),
            default => "Loop terminado.\n\n".($lastStep['resumen'] ?? 'Complete los pasos posibles.'),
        };
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

            if (! is_array($result) || ! isset($result['confirmation_token'])) {
                continue;
            }

            $pending->put($result['confirmation_token'], [
                'operation' => $result['operacion'] ?? $result['operation'] ?? $toolResult['name'],
                'confirmation_token' => $result['confirmation_token'],
                'summary' => $result['summary'] ?? $result,
            ]);
        }

        return $pending->values()->all();
    }
}

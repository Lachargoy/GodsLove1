<?php

namespace App\Services\Ai;

use App\Ai\Agents\OperationsAgent;
use App\Models\User;
use Laravel\Ai\Messages\AssistantMessage;
use Laravel\Ai\Messages\UserMessage;
use RuntimeException;

class OpenRouterAssistantService
{
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
        $prompt = $this->appendConfirmationContext($prompt, $pendingConfirmations);

        $response = OperationsAgent::make(messages: $this->toSdkMessages($previousMessages))
            ->prompt(
                prompt: $prompt,
                provider: 'openrouter',
                model: (string) config('services.openrouter.model', 'openai/gpt-4o-mini'),
                timeout: (int) config('services.openrouter.timeout', 45),
            );

        $toolResults = $response->toolResults
            ->map(fn ($toolResult): array => [
                'name' => $toolResult->name,
                'result' => $this->decodeToolResult($toolResult->result),
            ])
            ->values()
            ->all();

        $assistantMessage = [
            'role' => 'assistant',
            'content' => trim($response->text) !== '' ? $response->text : 'Listo.',
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

    private function decodeToolResult(mixed $result): mixed
    {
        if (! is_string($result)) {
            return $result;
        }

        $decoded = json_decode($result, true);

        return json_last_error() === JSON_ERROR_NONE ? $decoded : $result;
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

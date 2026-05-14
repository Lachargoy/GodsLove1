<?php

use App\Ai\Agents\OperationsAgent;
use App\Models\User;
use App\Services\Ai\OpenRouterAssistantService;
use Database\Seeders\CategoriaGastoSeeder;
use Database\Seeders\CategoriaInsumoSeeder;
use Database\Seeders\CategoriaProductoSeeder;
use Database\Seeders\InsumoSeeder;
use Database\Seeders\InventoryCategorySeeder;
use Database\Seeders\ProductoInsumoSeeder;
use Database\Seeders\ProductoSeeder;
use Database\Seeders\UnitSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Ai\Responses\Data\ToolCall;

uses(RefreshDatabase::class);

beforeEach(function () {
    config([
        'services.openrouter.key' => 'test-key',
        'services.openrouter.model' => 'openai/test-model',
        'services.openrouter.timeout' => 30,
    ]);
});

test('asistente page loads for authenticated users', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->get('/asistente')
        ->assertSuccessful()
        ->assertSee('Operador IA');
});

test('openrouter assistant uses laravel ai agent', function () {
    OperationsAgent::fake([
        'La caja esta cerrada. Puedo preparar apertura si me das el monto inicial.',
    ])->preventStrayPrompts();

    $user = User::factory()->create();
    $assistant = app(OpenRouterAssistantService::class);

    $response = $assistant->respond([
        ['role' => 'user', 'content' => 'Dame el resumen de caja'],
    ], $user);

    expect($response['reply'])->toContain('caja')
        ->and($response['messages'])->toHaveCount(2)
        ->and($response['tool_results'])->toBe([]);

    OperationsAgent::assertPrompted('Dame el resumen de caja');
});

test('laravel ai agent can invoke operations tool', function () {
    $this->seed([
        UnitSeeder::class,
        InventoryCategorySeeder::class,
        CategoriaProductoSeeder::class,
        CategoriaInsumoSeeder::class,
        CategoriaGastoSeeder::class,
        ProductoSeeder::class,
        InsumoSeeder::class,
        ProductoInsumoSeeder::class,
    ]);

    OperationsAgent::fake([
        new ToolCall(
            'call_1',
            'operacion_godslove',
            [
                'action' => 'buscar_producto',
                'search' => 'Cono',
            ],
            'call_1',
        ),
        'Encontre productos de cono activos con sus precios.',
    ])->preventStrayPrompts();

    $user = User::factory()->create();
    $assistant = app(OpenRouterAssistantService::class);

    $response = $assistant->respond([
        ['role' => 'user', 'content' => 'Busca productos de cono'],
    ], $user);

    expect($response['reply'])->toContain('cono')
        ->and($response['tool_results'][0]['name'])->toBe('operacion_godslove')
        ->and($response['tool_results'][0]['result']['productos'][0]['nombre'])->toContain('Cono');
});

test('prepared confirmations are kept for the next agent prompt', function () {
    OperationsAgent::fake(function (string $prompt): string {
        expect($prompt)->toContain('confirmation_token')
            ->and($prompt)->toContain('tok_test');

        return 'Confirmo usando el token interno.';
    })->preventStrayPrompts();

    $user = User::factory()->create();
    $assistant = app(OpenRouterAssistantService::class);

    $assistant->respond([
        ['role' => 'user', 'content' => 'si confirma'],
    ], $user, [
        [
            'operation' => 'venta',
            'confirmation_token' => 'tok_test',
            'summary' => ['total' => 50],
        ],
    ]);
});

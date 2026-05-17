<?php

use App\Ai\Agents\OperationsAgent;
use App\Models\CorteCaja;
use App\Models\Insumo;
use App\Models\Producto;
use App\Models\User;
use App\Models\Venta;
use App\Services\Ai\OpenRouterAssistantService;
use App\Services\Mcp\OperationsAssistantService;
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
use Livewire\Volt\Volt;

uses(RefreshDatabase::class);

beforeEach(function () {
    config([
        'services.openrouter.key' => 'test-key',
        'services.openrouter.model' => 'qwen/qwen3.5-122b-a10b',
        'services.openrouter.timeout' => 120,
    ]);
});

test('asistente page loads for authenticated users', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->get('/asistente')
        ->assertSuccessful()
        ->assertSee('Consola IA de operaciones')
        ->assertSee('qwen/qwen3.5-122b-a10b');
});

test('assistant chat restores session history', function () {
    $user = User::factory()->create();

    session([
        'asistente.messages.'.$user->id => [
            ['role' => 'assistant', 'content' => 'Mensaje guardado de prueba'],
            ['role' => 'user', 'content' => 'Mi pedido anterior'],
        ],
    ]);

    Volt::actingAs($user)
        ->test('asistente.index')
        ->assertSee('Mensaje guardado de prueba')
        ->assertSee('Mi pedido anterior')
        ->assertSee('2 mensajes visibles');
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

test('openrouter timeout uses configured two minute value', function () {
    config(['services.openrouter.timeout' => 120]);

    $assistant = app(OpenRouterAssistantService::class);
    $method = new ReflectionMethod($assistant, 'openRouterTimeout');
    $method->setAccessible(true);

    expect($method->invoke($assistant))->toBe(120);
});

test('openrouter assistant extends php execution limit beyond model timeout', function () {
    $assistant = app(OpenRouterAssistantService::class);
    $previousLimit = ini_get('max_execution_time');
    ini_set('max_execution_time', '30');

    $method = new ReflectionMethod($assistant, 'extendPhpExecutionLimit');
    $method->setAccessible(true);
    $method->invoke($assistant, 120);

    expect((int) ini_get('max_execution_time'))->toBeGreaterThanOrEqual(135);

    ini_set('max_execution_time', $previousLimit);
});

test('assistant page turns model timeout into user friendly error', function () {
    OperationsAgent::fake(function (): string {
        throw new RuntimeException('cURL error 28: Operation timed out after 120001 milliseconds');
    })->preventStrayPrompts();

    $user = User::factory()->create();

    Volt::actingAs($user)
        ->test('asistente.index')
        ->set('prompt', 'Dame resumen de caja')
        ->call('enviar')
        ->assertSet('error', 'El modelo tardo demasiado en responder. Intenta de nuevo o usa un modelo mas rapido en OPENROUTER_MODEL.');
});

test('assistant loop mode plans and executes until completed', function () {
    OperationsAgent::fake([
        'Plan: 1. Consultar caja. 2. Resumir estado. Empiezo ejecucion controlada.',
        'LOOP_COMPLETED: La caja esta cerrada y no hay mas acciones necesarias.',
    ])->preventStrayPrompts();

    $user = User::factory()->create();

    Volt::actingAs($user)
        ->test('asistente.index')
        ->set('loopMode', true)
        ->set('prompt', 'Revisa la caja y dime que sigue')
        ->call('enviar')
        ->assertSet('messages.1.role', 'user')
        ->assertSet('messages.2.content', "Loop terminado.\n\nLOOP_COMPLETED: La caja esta cerrada y no hay mas acciones necesarias.")
        ->assertSet('lastLoopSteps.0.tipo', 'plan')
        ->assertSet('lastLoopSteps.1.estado', 'completed')
        ->assertSee('Loop terminado');
});

test('assistant loop mode pauses when a prepared operation needs confirmation', function () {
    $this->seed([
        UnitSeeder::class,
        InventoryCategorySeeder::class,
        CategoriaProductoSeeder::class,
        CategoriaInsumoSeeder::class,
        CategoriaGastoSeeder::class,
    ]);

    OperationsAgent::fake([
        'Plan: 1. Preparar el alta. 2. Esperar confirmacion. Empiezo ejecucion controlada.',
        new ToolCall(
            'call_loop_insumo',
            'operacion_godslove',
            [
                'action' => 'preparar_alta_insumo',
                'nombre' => 'Azucar loop AI',
                'unidad_medida' => 'kg',
                'cantidad_actual' => 5,
                'cantidad_minima' => 1,
                'costo_unitario' => 22,
            ],
            'call_loop_insumo',
        ),
        'Prepare el alta de Azucar loop AI y necesito confirmacion.',
    ])->preventStrayPrompts();

    $user = User::factory()->create();

    Volt::actingAs($user)
        ->test('asistente.index')
        ->set('loopMode', true)
        ->set('prompt', 'Da de alta azucar loop con 5 kg a 22 pesos')
        ->call('enviar')
        ->assertSet('lastLoopSteps.1.estado', 'waiting_confirmation')
        ->assertSet('pendingConfirmations.0.operation', 'alta_insumo')
        ->assertSee('Loop pausado');
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

    $response = $assistant->respond([
        ['role' => 'user', 'content' => 'revisa el contexto pendiente'],
    ], $user, [
        [
            'operation' => 'venta',
            'confirmation_token' => 'tok_test',
            'summary' => ['total' => 50],
        ],
    ]);

    expect($response['reply'])->toContain('Confirmo');
});

test('assistant confirms a single pending sale without asking the model again', function () {
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

    OperationsAgent::fake([])->preventStrayPrompts();

    $user = User::factory()->create();
    $this->actingAs($user);

    CorteCaja::query()->create([
        'user_id' => $user->id,
        'fecha_apertura' => now(),
        'monto_inicial' => 500,
        'estado' => 'abierto',
    ]);

    $product = Producto::query()->where('nombre', 'Cono sencillo')->firstOrFail();
    $prepared = app(OperationsAssistantService::class)->prepareSale([
        ['producto_id' => $product->id, 'cantidad' => 1],
    ]);

    $assistant = app(OpenRouterAssistantService::class);
    $response = $assistant->respond([
        ['role' => 'user', 'content' => 'si confirmo'],
    ], $user, [[
        'operation' => 'venta',
        'confirmation_token' => $prepared['confirmation_token'],
        'summary' => $prepared['resumen'],
    ]]);

    expect($response['reply'])->toContain('Venta confirmada')
        ->and($response['tool_results'][0]['result']['status'])->toBe('confirmed')
        ->and($response['pending_confirmations'])->toBe([])
        ->and(Venta::query()->count())->toBe(1);

    OperationsAgent::assertNeverPrompted();
});

test('assistant cancels pending confirmations directly', function () {
    OperationsAgent::fake([])->preventStrayPrompts();

    $user = User::factory()->create();
    $assistant = app(OpenRouterAssistantService::class);
    $response = $assistant->respond([
        ['role' => 'user', 'content' => 'no, cancela eso'],
    ], $user, [[
        'operation' => 'venta',
        'confirmation_token' => 'tok_cancel',
        'summary' => ['total' => 100],
    ]]);

    expect($response['reply'])->toContain('cancele')
        ->and($response['tool_results'])->toBe([])
        ->and($response['pending_confirmations'])->toBe([]);

    OperationsAgent::assertNeverPrompted();
});

test('laravel ai agent can prepare insumo creation through operations tool', function () {
    $this->seed([
        UnitSeeder::class,
        InventoryCategorySeeder::class,
        CategoriaProductoSeeder::class,
        CategoriaInsumoSeeder::class,
        CategoriaGastoSeeder::class,
    ]);

    OperationsAgent::fake([
        new ToolCall(
            'call_insumo',
            'operacion_godslove',
            [
                'action' => 'preparar_alta_insumo',
                'nombre' => 'Azucar morena AI',
                'unidad_medida' => 'kg',
                'cantidad_actual' => 5,
                'cantidad_minima' => 1,
                'costo_unitario' => 22,
            ],
            'call_insumo',
        ),
        'Prepare el alta del insumo Azucar morena AI y necesito tu confirmacion.',
    ])->preventStrayPrompts();

    $user = User::factory()->create();
    $assistant = app(OpenRouterAssistantService::class);

    $response = $assistant->respond([
        ['role' => 'user', 'content' => 'Da de alta azucar morena con 5 kg a 22 pesos'],
    ], $user);

    expect($response['tool_results'][0]['result']['status'])->toBe('requires_confirmation')
        ->and($response['pending_confirmations'])->toHaveCount(1)
        ->and(Insumo::query()->where('nombre', 'Azucar morena AI')->exists())->toBeFalse();
});

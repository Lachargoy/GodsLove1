<?php

use App\Ai\Agents\IntentParserAgent;
use App\Ai\Agents\OperationsAgent;
use App\Models\CategoriaProducto;
use App\Models\Category;
use App\Models\CorteCaja;
use App\Models\Insumo;
use App\Models\InventoryItem;
use App\Models\Producto;
use App\Models\ProductOptionGroup;
use App\Models\ProductOptionItem;
use App\Models\Unit;
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

function fakeNonSaleIntent(): void
{
    IntentParserAgent::fake([
        [
            'intent' => 'otra',
            'confidence' => 0.2,
            'items' => [],
            'metodo_pago' => 'desconocido',
            'missing_fields' => [],
            'notes' => null,
        ],
    ])->preventStrayPrompts();
}

test('asistente page loads for authenticated users', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->get('/asistente')
        ->assertSuccessful()
        ->assertSee('Asistente de operaciones')
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
        ->assertSee('2 mensajes');
});

test('openrouter assistant uses laravel ai agent', function () {
    fakeNonSaleIntent();

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
    fakeNonSaleIntent();

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
    fakeNonSaleIntent();

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

    IntentParserAgent::fake([
        [
            'intent' => 'otra',
            'confidence' => 0.2,
            'items' => [],
            'metodo_pago' => 'desconocido',
            'missing_fields' => [],
            'notes' => null,
        ],
        [
            'intent' => 'otra',
            'confidence' => 0.2,
            'items' => [],
            'metodo_pago' => 'desconocido',
            'missing_fields' => [],
            'notes' => null,
        ],
    ])->preventStrayPrompts();

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

    fakeNonSaleIntent();

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
    fakeNonSaleIntent();

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

    IntentParserAgent::fake([])->preventStrayPrompts();
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

    IntentParserAgent::assertNeverPrompted();
    OperationsAgent::assertNeverPrompted();
});

test('assistant prepares straightforward sales without relying on the model', function () {
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

    $saleIntent = [
        'intent' => 'registrar_venta',
        'confidence' => 0.95,
        'items' => [
            [
                'producto_nombre' => 'Cono sencillo',
                'cantidad' => 2,
                'selected_options' => [],
            ],
        ],
        'metodo_pago' => 'efectivo',
        'missing_fields' => [],
        'notes' => null,
    ];

    IntentParserAgent::fake([
        $saleIntent,
        [
            'intent' => 'registrar_venta',
            'confidence' => 0.95,
            'items' => [
                [
                    'producto_nombre' => 'Cono sencillo',
                    'cantidad' => 2,
                    'selected_options' => [],
                ],
            ],
            'metodo_pago' => 'efectivo',
            'missing_fields' => [],
            'notes' => null,
        ],
    ])->preventStrayPrompts();
    OperationsAgent::fake([])->preventStrayPrompts();

    $user = User::factory()->create();
    $this->actingAs($user);

    CorteCaja::query()->create([
        'user_id' => $user->id,
        'fecha_apertura' => now(),
        'monto_inicial' => 500,
        'estado' => 'abierto',
    ]);

    $assistant = app(OpenRouterAssistantService::class);
    $response = $assistant->respond([
        ['role' => 'user', 'content' => 'registra una venta de 2 conos sencillos en efectivo'],
    ], $user);

    expect($response['reply'])->toContain('Venta preparada')
        ->and($response['reply'])->toContain('Cono sencillo')
        ->and($response['tool_results'][0]['result']['status'])->toBe('requires_confirmation')
        ->and($response['tool_results'][0]['result']['operacion'])->toBe('venta')
        ->and($response['pending_confirmations'][0]['operation'])->toBe('venta')
        ->and(Venta::query()->count())->toBe(0);

    IntentParserAgent::assertPrompted(fn ($prompt): bool => str_contains($prompt->prompt, 'registra una venta'));
    OperationsAgent::assertNeverPrompted();
});

test('assistant keeps an incomplete configurable sale and completes it with the next flavor message', function () {
    $saleIntent = [
        'intent' => 'registrar_venta',
        'confidence' => 0.95,
        'items' => [
            [
                'producto_nombre' => 'Cono sencillo',
                'cantidad' => 2,
                'selected_options' => [],
            ],
        ],
        'metodo_pago' => 'efectivo',
        'missing_fields' => [],
        'notes' => null,
    ];

    IntentParserAgent::fake([
        $saleIntent,
        $saleIntent,
    ])->preventStrayPrompts();
    OperationsAgent::fake([])->preventStrayPrompts();

    $user = User::factory()->create();
    $this->actingAs($user);

    CorteCaja::query()->create([
        'user_id' => $user->id,
        'fecha_apertura' => now(),
        'monto_inicial' => 500,
        'estado' => 'abierto',
    ]);

    $unit = Unit::query()->create([
        'name' => 'litro',
        'abbreviation' => 'L',
        'allows_decimals' => true,
    ]);

    $category = Category::query()->create([
        'name' => 'Sabores',
        'type' => 'inventory_item',
        'is_active' => true,
    ]);

    $legacyCategory = CategoriaProducto::query()->create([
        'nombre' => 'Conos',
        'activo' => true,
    ]);

    $nuez = InventoryItem::query()->create([
        'category_id' => $category->id,
        'unit_id' => $unit->id,
        'name' => 'Nuez',
        'current_stock' => 10,
        'minimum_stock' => 1,
        'average_cost' => 30,
        'allows_decimals' => true,
        'is_sellable' => false,
        'is_consumable' => true,
        'is_active' => true,
    ]);

    $product = Producto::query()->create([
        'categoria_producto_id' => $legacyCategory->id,
        'nombre' => 'Cono sencillo',
        'descripcion' => 'Cono configurable para pruebas',
        'precio_venta' => 25,
        'costo_estimado' => 0,
        'product_type' => 'configurable',
        'activo' => true,
    ]);

    $group = ProductOptionGroup::query()->create([
        'product_id' => $product->id,
        'name' => 'Sabores',
        'required_quantity' => 1,
        'min_quantity' => 1,
        'max_quantity' => 1,
    ]);

    ProductOptionItem::query()->create([
        'product_option_group_id' => $group->id,
        'inventory_item_id' => $nuez->id,
        'quantity_per_selection' => 0.12,
        'extra_price' => 0,
        'is_active' => true,
    ]);

    $assistant = app(OpenRouterAssistantService::class);
    $firstResponse = $assistant->respond([
        ['role' => 'user', 'content' => 'registra una venta de 2 conos sencillos en efectivo'],
    ], $user);

    expect($firstResponse['reply'])->toContain('venta en borrador')
        ->and($firstResponse['reply'])->toContain('Sabores')
        ->and($firstResponse['pending_confirmations'][0]['operation'])->toBe('venta_incompleta')
        ->and(Venta::query()->count())->toBe(0);

    $canceled = $assistant->respond([
        ...$firstResponse['messages'],
        ['role' => 'user', 'content' => 'cancelar'],
    ], $user, $firstResponse['pending_confirmations']);

    expect($canceled['reply'])->toContain('cancele')
        ->and($canceled['pending_confirmations'])->toBe([]);

    $loopResponse = $assistant->planAndExecute([
        ['role' => 'user', 'content' => 'registra una venta de 2 conos sencillos en efectivo'],
    ], $user);

    expect($loopResponse['lastLoopSteps'] ?? $loopResponse['loop_steps'])->not->toBeEmpty()
        ->and($loopResponse['loop_steps'][0]['estado'])->toBe('waiting_input')
        ->and($loopResponse['pending_confirmations'][0]['operation'])->toBe('venta_incompleta');

    $secondResponse = $assistant->respond([
        ...$firstResponse['messages'],
        ['role' => 'user', 'content' => 'los 2 fueron de nuez'],
    ], $user, $firstResponse['pending_confirmations']);

    expect($secondResponse['reply'])->toContain('Venta preparada')
        ->and($secondResponse['reply'])->toContain('Cono sencillo')
        ->and($secondResponse['tool_results'][0]['result']['status'])->toBe('requires_confirmation')
        ->and($secondResponse['pending_confirmations'][0]['operation'])->toBe('venta')
        ->and(Venta::query()->count())->toBe(0);

    $confirmed = $assistant->respond([
        ...$secondResponse['messages'],
        ['role' => 'user', 'content' => 'confirmar'],
    ], $user, $secondResponse['pending_confirmations']);

    expect($confirmed['reply'])->toContain('Venta confirmada')
        ->and($confirmed['pending_confirmations'])->toBe([])
        ->and(Venta::query()->count())->toBe(1);

    IntentParserAgent::assertPrompted(fn ($prompt): bool => str_contains($prompt->prompt, 'registra una venta'));
    OperationsAgent::assertNeverPrompted();
});

test('assistant blocks new writes while another operation is pending', function () {
    IntentParserAgent::fake([
        [
            'intent' => 'registrar_venta',
            'confidence' => 0.95,
            'items' => [
                [
                    'producto_nombre' => 'Cono sencillo',
                    'cantidad' => 2,
                    'selected_options' => [],
                ],
            ],
            'metodo_pago' => 'efectivo',
            'missing_fields' => [],
            'notes' => null,
        ],
    ])->preventStrayPrompts();
    OperationsAgent::fake([])->preventStrayPrompts();

    $user = User::factory()->create();
    $assistant = app(OpenRouterAssistantService::class);
    $response = $assistant->respond([
        ['role' => 'user', 'content' => 'tambien registra una venta de 2 conos sencillos en efectivo'],
    ], $user, [[
        'operation' => 'abrir_caja',
        'confirmation_token' => 'tok_open_cash',
        'summary' => ['monto_inicial' => 500],
    ]]);

    expect($response['reply'])->toContain('no voy a mezclarla')
        ->and($response['pending_confirmations'][0]['operation'])->toBe('abrir_caja')
        ->and($response['tool_results'])->toBe([]);

    IntentParserAgent::assertPrompted(fn ($prompt): bool => str_contains($prompt->prompt, 'tambien registra una venta'));
    OperationsAgent::assertNeverPrompted();
});

test('assistant fails closed when intent parser is unavailable for a possible sale', function () {
    IntentParserAgent::fake(function (): never {
        throw new RuntimeException('parser unavailable');
    })->preventStrayPrompts();
    OperationsAgent::fake([])->preventStrayPrompts();

    $user = User::factory()->create();
    $assistant = app(OpenRouterAssistantService::class);
    $response = $assistant->respond([
        ['role' => 'user', 'content' => 'registra una venta de 2 conos sencillos en efectivo'],
    ], $user);

    expect($response['reply'])->toContain('No pude interpretar con seguridad')
        ->and($response['tool_results'])->toBe([])
        ->and($response['pending_confirmations'])->toBe([]);

    OperationsAgent::assertNeverPrompted();
});

test('assistant cancels pending confirmations directly', function () {
    IntentParserAgent::fake([])->preventStrayPrompts();
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

    IntentParserAgent::assertNeverPrompted();
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

    fakeNonSaleIntent();

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

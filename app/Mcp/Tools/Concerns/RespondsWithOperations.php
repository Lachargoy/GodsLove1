<?php

namespace App\Mcp\Tools\Concerns;

use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Throwable;

trait RespondsWithOperations
{
    /**
     * @param  callable(): array<string, mixed>  $callback
     */
    protected function operationResponse(callable $callback): ResponseFactory
    {
        try {
            return Response::structured($callback());
        } catch (Throwable $throwable) {
            return Response::structured([
                'status' => 'error',
                'error' => $throwable->getMessage(),
            ]);
        }
    }
}

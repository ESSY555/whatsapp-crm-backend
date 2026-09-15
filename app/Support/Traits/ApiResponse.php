<?php

namespace App\Support\Traits;

use Illuminate\Http\JsonResponse;

trait ApiResponse
{
    /**
     * Standard success envelope. Passing no data yields `"data": {}`; an
     * explicit empty array is preserved as `[]` so list endpoints stay lists.
     */
    protected function successResponse(string $message = 'Operation completed successfully.', mixed $data = null, int $status = 200): JsonResponse
    {
        return response()->json([
            'success' => true,
            'message' => $message,
            'data' => $data ?? new \stdClass(),
        ], $status);
    }

    protected function errorResponse(string $message = 'An error occurred.', int $status = 400): JsonResponse
    {
        return response()->json([
            'success' => false,
            'message' => $message,
        ], $status);
    }
}

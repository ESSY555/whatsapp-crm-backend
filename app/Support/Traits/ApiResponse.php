<?php

namespace App\Support\Traits;

use Illuminate\Http\JsonResponse;

trait ApiResponse
{
    protected function successResponse(string $message = 'Operation completed successfully.', mixed $data = [], int $status = 200): JsonResponse
    {
        return response()->json([
            'success' => true,
            'message' => $message,
            'data' => empty($data) ? new \stdClass() : $data,
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

<?php

namespace App\Support;

use Illuminate\Http\JsonResponse;
use Illuminate\Pagination\LengthAwarePaginator;

trait ApiResponse
{
    /**
     * Return a success response.
     */
    protected function success(mixed $data = null, ?string $message = null, int $statusCode = 200): JsonResponse
    {
        $response = [
            'success' => true,
            'message' => $message,
            'data' => $data,
        ];

        return response()->json($response, $statusCode);
    }

    /**
     * Return a created response.
     */
    protected function created(mixed $data = null, ?string $message = null): JsonResponse
    {
        return $this->success($data, $message ?? __('messages.created'), 201);
    }

    /**
     * Return an error response.
     */
    protected function error(string $message, int $statusCode = 400, mixed $errors = null): JsonResponse
    {
        $response = [
            'success' => false,
            'message' => $message,
        ];

        if ($errors !== null) {
            $response['errors'] = $errors;
        }

        return response()->json($response, $statusCode);
    }

    /**
     * Return a paginated response.
     */
    protected function paginated(LengthAwarePaginator $paginator, string $resourceClass, array $extraMeta = []): JsonResponse
    {
        $meta = [
            'current_page' => $paginator->currentPage(),
            'last_page' => $paginator->lastPage(),
            'per_page' => $paginator->perPage(),
            'total' => $paginator->total(),
        ];

        if (!empty($extraMeta)) {
            $meta = array_merge($meta, $extraMeta);
        }

        return response()->json([
            'success' => true,
            'message' => null,
            'data' => $resourceClass::collection($paginator->items()),
            'meta' => $meta,
        ]);
    }

    /**
     * Return a no-content response.
     */
    protected function noContent(): JsonResponse
    {
        return response()->json(null, 204);
    }
}

<?php

namespace App\Support;

use Illuminate\Http\JsonResponse;

/**
 * One response envelope for the whole API, so the client never has to guess
 * where the payload lives.
 */
final class ApiResponse
{
    public static function data(mixed $data, array $meta = [], int $status = 200): JsonResponse
    {
        $body = ['data' => $data];

        if ($meta !== []) {
            $body['meta'] = $meta;
        }

        return response()->json($body, $status);
    }

    public static function message(string $message, array $extra = [], int $status = 200): JsonResponse
    {
        return response()->json(array_merge(['message' => $message], $extra), $status);
    }

    public static function error(string $code, string $message, array $extra = [], int $status = 400): JsonResponse
    {
        return response()->json(array_merge([
            'error' => $code,
            'message' => $message,
        ], $extra), $status);
    }

    public static function noContent(): JsonResponse
    {
        return response()->json(null, 204);
    }
}

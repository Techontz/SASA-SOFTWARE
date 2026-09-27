<?php

namespace App\Support;

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\TooManyRequestsHttpException;
use Throwable;

/**
 * Errors a person can act on. "500 Internal Server Error" tells a field officer
 * nothing; "We couldn't save this record — your changes are still on this
 * device" tells them exactly what happened and what to expect.
 */
final class ApiExceptionRenderer
{
    public static function render(Throwable $e, Request $request): JsonResponse
    {
        $requestId = $request->attributes->get('sasa_request_id');

        [$status, $code, $message, $extra] = match (true) {
            $e instanceof ValidationException => [
                422,
                'validation_failed',
                'Some of the information provided needs attention.',
                ['errors' => $e->errors()],
            ],
            $e instanceof AuthenticationException => [
                401,
                'unauthenticated',
                'Your session has expired. Please sign in again.',
                [],
            ],
            $e instanceof AuthorizationException => [
                403,
                'forbidden',
                $e->getMessage() !== '' && $e->getMessage() !== 'This action is unauthorized.'
                    ? $e->getMessage()
                    : 'You do not have permission to do this.',
                [],
            ],
            $e instanceof ModelNotFoundException, $e instanceof NotFoundHttpException => [
                404,
                'not_found',
                'We could not find that record. It may have been archived, or it may belong to another project.',
                [],
            ],
            $e instanceof TooManyRequestsHttpException => [
                429,
                'rate_limited',
                'Too many requests. Please wait a moment and try again.',
                [],
            ],
            $e instanceof DomainRuleException => [
                $e->status,
                $e->errorCode,
                $e->getMessage(),
                $e->context,
            ],
            $e instanceof HttpExceptionInterface => [
                $e->getStatusCode(),
                'http_error',
                $e->getMessage() ?: 'The request could not be completed.',
                [],
            ],
            default => [
                500,
                'server_error',
                'We could not complete that action. Nothing was saved on the server — please try again in a moment.',
                [],
            ],
        };

        if ($status >= 500) {
            report($e);
        }

        $body = array_merge([
            'error' => $code,
            'message' => $message,
            'request_id' => $requestId,
        ], $extra);

        if (config('app.debug') && $status >= 500) {
            $body['debug'] = [
                'exception' => $e::class,
                'detail' => $e->getMessage(),
                'file' => $e->getFile().':'.$e->getLine(),
            ];
        }

        return response()->json($body, $status);
    }
}

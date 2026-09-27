<?php

namespace App\Http\Middleware;

use App\Domain\Tenancy\TenantContext;
use App\Support\ApiResponse;
use Closure;
use Illuminate\Http\Request;

/**
 * Backend permission enforcement. Hiding a button in React is a UX nicety;
 * this is the actual control.
 *
 * Usage: `->middleware('permission:grievance.resolve')`
 *        `->middleware('permission:grievance.view|grievance.manage')` (any of)
 */
class EnsurePermission
{
    public function __construct(private readonly TenantContext $context) {}

    public function handle(Request $request, Closure $next, string $permissions)
    {
        if (! $this->context->user() && $request->user()) {
            $this->context->setUser($request->user());
        }

        $required = explode('|', $permissions);

        if (! $this->context->canAny(...$required)) {
            return ApiResponse::error(
                'forbidden',
                'You do not have permission to do this on this project.',
                ['required_permission' => $required],
                403
            );
        }

        return $next($request);
    }
}

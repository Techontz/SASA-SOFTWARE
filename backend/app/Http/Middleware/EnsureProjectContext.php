<?php

namespace App\Http\Middleware;

use App\Domain\Tenancy\TenantContext;
use App\Models\Project;
use App\Models\ProjectMembership;
use App\Support\ApiResponse;
use Closure;
use Illuminate\Http\Request;

/**
 * Resolves and VALIDATES the project the request operates in.
 *
 * The client sends `X-Sasa-Project` (or ?project_id=). We look up the
 * membership; if the authenticated user has none, the project does not exist
 * as far as this request is concerned. No client-supplied organisation_id is
 * ever trusted.
 *
 * Usage: `->middleware('project')` (required) or `->middleware('project:optional')`.
 */
class EnsureProjectContext
{
    public function __construct(private readonly TenantContext $context) {}

    public function handle(Request $request, Closure $next, string $mode = 'required')
    {
        $user = $request->user();

        if (! $user) {
            return ApiResponse::error('unauthenticated', 'Your session has expired. Please sign in again.', [], 401);
        }

        $this->context->setUser($user);

        $projectId = $request->header('X-Sasa-Project')
            ?: $request->input('project_id')
            ?: $user->default_project_id;

        if (! $projectId) {
            if ($mode === 'optional') {
                return $next($request);
            }

            return ApiResponse::error(
                'project_required',
                'Choose a project before continuing.',
                [],
                400
            );
        }

        $membership = ProjectMembership::query()
            ->with('role.permissions')
            ->where('user_id', $user->id)
            ->where('project_id', $projectId)
            ->where('status', 'active')
            ->first();

        if (! $membership) {
            if ($user->is_system_admin) {
                $project = Project::find($projectId);

                if ($project) {
                    $this->context->setProject($project, null);
                    $request->attributes->set('sasa_project', $project);

                    return $next($request);
                }
            }

            if ($mode === 'optional') {
                return $next($request);
            }

            // Deliberately indistinguishable from "does not exist".
            return ApiResponse::error(
                'not_found',
                'We could not find that project, or you do not have access to it.',
                [],
                404
            );
        }

        $project = $membership->project;

        if (! $project || $project->status === 'archived') {
            return ApiResponse::error('not_found', 'That project is no longer available.', [], 404);
        }

        $this->context->setProject($project, $membership);
        $request->attributes->set('sasa_project', $project);
        $request->attributes->set('sasa_membership', $membership);

        return $next($request);
    }
}

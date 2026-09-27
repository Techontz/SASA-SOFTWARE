<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Support\ApiResponse;
use App\Support\QueryFilters;
use Illuminate\Http\Request;

/**
 * The audit trail. Sensitive-view events are queryable on their own, because
 * "who read this confidential case" is the question an investigation asks.
 */
class AuditController extends Controller
{
    public function index(Request $request)
    {
        abort_unless($this->context()->can('audit.view'), 403, 'You do not have permission to see the audit trail.');

        $query = AuditLog::query()
            ->where('project_id', $this->project()->id)
            ->with('user:id,name');

        if (! $this->context()->can('audit.view_sensitive')) {
            $query->where('is_sensitive_view', false);
        }

        QueryFilters::apply($query, $request, [
            'action', 'entity_type', 'entity_id', 'user_id',
            'is_sensitive_view' => fn ($q, $v) => $q->where('is_sensitive_view', filter_var($v, FILTER_VALIDATE_BOOLEAN)),
            'search' => fn ($q, $v) => $q->where(fn ($w) => $w
                ->where('summary', 'like', "%$v%")
                ->orWhere('entity_reference', 'like', "%$v%")
                ->orWhere('user_name', 'like', "%$v%")),
        ]);

        QueryFilters::dateRange($query, $request, 'created_at');

        return ApiResponse::data(
            $query->orderByDesc('created_at')
                ->paginate(QueryFilters::perPage($request, 50))
                ->withQueryString()
        );
    }

    /** The per-record history tab. */
    public function forEntity(Request $request, string $type, int $id)
    {
        abort_unless($this->context()->can('audit.view'), 403, 'You do not have permission to see record history.');

        $logs = AuditLog::query()
            ->where('project_id', $this->project()->id)
            ->where('entity_type', $type)
            ->where('entity_id', $id)
            ->when(! $this->context()->can('audit.view_sensitive'), fn ($q) => $q->where('is_sensitive_view', false))
            ->with('user:id,name')
            ->orderByDesc('created_at')
            ->limit(200)
            ->get();

        return ApiResponse::data($logs);
    }

    public function sensitiveViews(Request $request)
    {
        abort_unless($this->context()->can('audit.view_sensitive'), 403, 'You do not have permission to see sensitive-view events.');

        $query = AuditLog::query()
            ->where('project_id', $this->project()->id)
            ->where('is_sensitive_view', true)
            ->with('user:id,name');

        QueryFilters::dateRange($query, $request, 'created_at');

        return ApiResponse::data(
            $query->orderByDesc('created_at')->paginate(QueryFilters::perPage($request, 50))
        );
    }

    public function actions()
    {
        abort_unless($this->context()->can('audit.view'), 403);

        return ApiResponse::data(
            AuditLog::where('project_id', $this->project()->id)
                ->select('action')
                ->distinct()
                ->orderBy('action')
                ->pluck('action')
        );
    }
}

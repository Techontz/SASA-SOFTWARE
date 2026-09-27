<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\SavedView;
use App\Support\ApiResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** "My open Level 4–5". "Overdue commitments — Northern district". */
class SavedViewController extends Controller
{
    public function index(Request $request)
    {
        $views = SavedView::query()
            ->where('project_id', $this->project()->id)
            ->visibleTo($request->user(), $this->context()->membership()?->role_id)
            ->when($request->filled('entity'), fn ($q) => $q->where('entity', $request->input('entity')))
            ->with('user:id,name')
            ->orderByDesc('is_pinned')->orderBy('name')
            ->get();

        return ApiResponse::data($views);
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'entity' => ['required', Rule::in(['stakeholders', 'engagements', 'engagement_plans', 'grievances', 'commitments', 'concerns'])],
            'name' => ['required', 'string', 'max:120'],
            'filters' => ['required', 'array'],
            'columns' => ['nullable', 'array'],
            'sort' => ['nullable', 'string', 'max:60'],
            'visibility' => ['nullable', Rule::in(['private', 'role', 'project'])],
            'shared_with_role_id' => ['nullable', 'integer', 'exists:roles,id'],
            'is_pinned' => ['nullable', 'boolean'],
        ]);

        $view = SavedView::create(array_merge($data, [
            'organisation_id' => $this->project()->organisation_id,
            'project_id' => $this->project()->id,
            'user_id' => $request->user()->id,
            'visibility' => $data['visibility'] ?? 'private',
        ]));

        return ApiResponse::data($view, [], 201);
    }

    public function update(Request $request, SavedView $savedView)
    {
        abort_unless(
            $savedView->user_id === $request->user()->id || $this->context()->can('project.manage'),
            403,
            'You can only change views you created.'
        );

        $data = $request->validate([
            'name' => ['sometimes', 'string', 'max:120'],
            'filters' => ['sometimes', 'array'],
            'columns' => ['sometimes', 'nullable', 'array'],
            'sort' => ['sometimes', 'nullable', 'string', 'max:60'],
            'visibility' => ['sometimes', Rule::in(['private', 'role', 'project'])],
            'shared_with_role_id' => ['sometimes', 'nullable', 'integer', 'exists:roles,id'],
            'is_pinned' => ['sometimes', 'boolean'],
        ]);

        $savedView->update($data);

        return ApiResponse::data($savedView->fresh());
    }

    public function destroy(Request $request, SavedView $savedView)
    {
        abort_unless(
            $savedView->user_id === $request->user()->id || $this->context()->can('project.manage'),
            403,
            'You can only remove views you created.'
        );

        $savedView->delete();

        return ApiResponse::noContent();
    }
}

<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Audit\AuditLogger;
use App\Http\Controllers\Controller;
use App\Models\ProjectMembership;
use App\Models\Role;
use App\Models\User;
use App\Support\ApiResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password;

/**
 * A user's role is per project: the same person may be a grievance officer on
 * one project and read-only on another.
 */
class ProjectMemberController extends Controller
{
    public function __construct(private readonly AuditLogger $audit) {}

    public function index(Request $request)
    {
        abort_unless($this->context()->can('user.view'), 403, 'You do not have permission to see project members.');

        $members = ProjectMembership::query()
            ->where('project_id', $this->project()->id)
            ->with(['user:id,name,email,phone,job_title,status,last_login_at', 'role:id,key,name'])
            ->when($request->filled('role'), fn ($q) => $q->whereHas('role', fn ($r) => $r->where('key', $request->input('role'))))
            ->orderBy('id')
            ->get();

        return ApiResponse::data($members->map(fn (ProjectMembership $m) => [
            'id' => $m->id,
            'user' => [
                'id' => $m->user->id,
                'name' => $m->user->name,
                'email' => $m->user->email,
                'phone' => $m->user->phone,
                'job_title' => $m->user->job_title,
                'status' => $m->user->status,
                'last_login_at' => $m->user->last_login_at?->toIso8601String(),
            ],
            'role' => ['id' => $m->role->id, 'key' => $m->role->key, 'name' => $m->role->name],
            'handling_groups' => $m->handling_groups ?? [],
            'status' => $m->status,
            'joined_at' => $m->joined_at?->toIso8601String(),
        ]));
    }

    /** Invite an existing user, or create one and add them to this project. */
    public function store(Request $request)
    {
        abort_unless($this->context()->can('user.manage'), 403, 'You do not have permission to add people to this project.');

        $data = $request->validate([
            'email' => ['required', 'email', 'max:255'],
            'name' => ['required_without:user_id', 'nullable', 'string', 'max:160'],
            'phone' => ['nullable', 'string', 'max:40'],
            'job_title' => ['nullable', 'string', 'max:120'],
            'role_id' => ['required', 'integer', 'exists:roles,id'],
            'handling_groups' => ['nullable', 'array'],
            'handling_groups.*' => ['string', 'max:60'],
        ]);

        $role = Role::findOrFail($data['role_id']);

        abort_if(
            $role->key === 'system_administrator' && ! $request->user()->is_system_admin,
            403,
            'Only a system administrator can grant the system administrator role.'
        );

        $membership = DB::transaction(function () use ($data, $request) {
            $user = User::firstOrCreate(
                ['email' => $data['email']],
                [
                    'name' => $data['name'] ?? Str::before($data['email'], '@'),
                    'phone' => $data['phone'] ?? null,
                    'job_title' => $data['job_title'] ?? null,
                    'organisation_id' => $this->project()->organisation_id,
                    'password' => Str::password(16),
                    'status' => 'invited',
                ]
            );

            return ProjectMembership::updateOrCreate(
                ['project_id' => $this->project()->id, 'user_id' => $user->id],
                [
                    'organisation_id' => $this->project()->organisation_id,
                    'role_id' => $data['role_id'],
                    'handling_groups' => $data['handling_groups'] ?? [],
                    'status' => 'active',
                    'joined_at' => now(),
                    'created_by' => $request->user()->id,
                ]
            );
        });

        $this->audit->record(
            action: 'membership.granted',
            entity: $membership,
            after: ['user' => $data['email'], 'role' => $role->key],
            summary: "Added {$data['email']} to this project as {$role->name}",
        );

        return ApiResponse::data($membership->load(['user:id,name,email,status', 'role:id,key,name']), [
            'message' => $membership->user->status === 'invited'
                ? 'Invited. Ask them to use "Forgot password" to set their own password.'
                : 'Added to the project.',
        ], 201);
    }

    public function update(Request $request, ProjectMembership $membership)
    {
        abort_unless($this->context()->can('user.manage'), 403, 'You do not have permission to change project roles.');
        abort_unless($membership->project_id === $this->project()->id, 404);

        $data = $request->validate([
            'role_id' => ['sometimes', 'integer', 'exists:roles,id'],
            'handling_groups' => ['sometimes', 'nullable', 'array'],
            'status' => ['sometimes', 'in:active,suspended'],
        ]);

        $before = ['role_id' => $membership->role_id, 'handling_groups' => $membership->handling_groups, 'status' => $membership->status];
        $membership->update($data);

        $this->audit->record(
            action: 'membership.changed',
            entity: $membership,
            before: $before,
            after: $data,
            summary: 'Project role or access changed',
        );

        return ApiResponse::data($membership->fresh(['user:id,name,email', 'role:id,key,name']));
    }

    public function destroy(ProjectMembership $membership)
    {
        abort_unless($this->context()->can('user.manage'), 403, 'You do not have permission to remove people from this project.');
        abort_unless($membership->project_id === $this->project()->id, 404);

        $membership->update(['status' => 'revoked']);

        $this->audit->record(
            action: 'membership.revoked',
            entity: $membership,
            summary: 'Access to this project removed',
        );

        return ApiResponse::message('Their access to this project has been removed. Their records and history remain.');
    }

    public function resetPassword(Request $request, ProjectMembership $membership)
    {
        abort_unless($this->context()->can('user.manage'), 403);
        abort_unless($membership->project_id === $this->project()->id, 404);

        $data = $request->validate(['password' => ['required', 'confirmed', Password::defaults()]]);

        $membership->user->update(['password' => $data['password'], 'status' => 'active']);
        $membership->user->tokens()->delete();

        $this->audit->record(
            action: 'user.password_reset_by_admin',
            entity: $membership->user,
            summary: 'Password reset by an administrator; all their sessions were ended',
        );

        return ApiResponse::message('Password set. All their existing sessions have been ended.');
    }
}

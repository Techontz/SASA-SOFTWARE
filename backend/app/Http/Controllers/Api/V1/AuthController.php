<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Audit\AuditLogger;
use App\Domain\Tenancy\TenantContext;
use App\Http\Controllers\Controller;
use App\Models\User;
use App\Support\ApiResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password as PasswordRule;

/**
 * Token authentication.
 *
 * Administrators get a shorter token life than field users, because the
 * consequence of a stolen administrator token is larger and a desk user can
 * sign in again easily. A field officer on a two-day trip cannot.
 */
class AuthController extends Controller
{
    public function __construct(private readonly AuditLogger $audit) {}

    public function login(Request $request)
    {
        $credentials = $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required', 'string'],
            'device_name' => ['nullable', 'string', 'max:120'],
        ]);

        $user = User::where('email', $credentials['email'])->first();

        if ($user && $user->isLocked()) {
            return ApiResponse::error(
                'account_locked',
                'Too many failed attempts. Try again in a few minutes, or ask an administrator to unlock your account.',
                ['locked_until' => $user->locked_until->toIso8601String()],
                423
            );
        }

        if (! $user || ! Hash::check($credentials['password'], $user->password)) {
            if ($user) {
                $attempts = $user->failed_login_attempts + 1;
                $max = (int) config('sasa.auth.max_login_attempts', 5);

                $user->forceFill([
                    'failed_login_attempts' => $attempts,
                    'locked_until' => $attempts >= $max
                        ? now()->addMinutes((int) config('sasa.auth.lockout_minutes', 15))
                        : null,
                ])->save();
            }

            $this->audit->record(
                action: 'auth.login_failed',
                summary: 'Failed sign-in for '.$credentials['email'],
                context: ['email' => $credentials['email']],
            );

            // Deliberately identical whether the account exists or not.
            return ApiResponse::error('invalid_credentials', 'That email address and password do not match.', [], 401);
        }

        if ($user->status !== 'active' || $user->archived_at !== null) {
            return ApiResponse::error(
                'account_inactive',
                'This account is not active. Ask your project administrator to reactivate it.',
                [],
                403
            );
        }

        $user->tokens()->where('name', 'like', 'sasa:%')->where('created_at', '<', now()->subDays(30))->delete();

        $token = $user->createToken(
            'sasa:'.($credentials['device_name'] ?? 'browser'),
            ['*'],
            now()->addMinutes($user->tokenTtlMinutes())
        );

        $user->forceFill([
            'failed_login_attempts' => 0,
            'locked_until' => null,
            'last_login_at' => now(),
            'last_login_ip' => $request->ip(),
        ])->save();

        // The audit trail must name who signed in, and at this point in the
        // request nothing else has established the authenticated user.
        app(TenantContext::class)->setUser($user);

        $this->audit->record(
            action: 'auth.login',
            entity: $user,
            summary: 'Signed in',
            context: ['device' => $credentials['device_name'] ?? null],
        );

        return ApiResponse::data([
            'token' => $token->plainTextToken,
            'expires_at' => $token->accessToken->expires_at?->toIso8601String(),
            'user' => $this->userPayload($user),
        ]);
    }

    public function me(Request $request)
    {
        return ApiResponse::data($this->userPayload($request->user()));
    }

    public function logout(Request $request)
    {
        $request->user()->currentAccessToken()?->delete();

        $this->audit->record(action: 'auth.logout', entity: $request->user(), summary: 'Signed out');

        return ApiResponse::message('You have been signed out.');
    }

    public function updateProfile(Request $request)
    {
        $data = $request->validate([
            'name' => ['sometimes', 'string', 'max:160'],
            'phone' => ['sometimes', 'nullable', 'string', 'max:40'],
            'job_title' => ['sometimes', 'nullable', 'string', 'max:120'],
            'locale' => ['sometimes', 'string', 'max:10'],
            'notification_preferences' => ['sometimes', 'array'],
            'notification_preferences.muted' => ['sometimes', 'boolean'],
            'notification_preferences.muted_events' => ['sometimes', 'array'],
        ]);

        $request->user()->update($data);

        return ApiResponse::data($this->userPayload($request->user()->fresh()));
    }

    public function changePassword(Request $request)
    {
        $data = $request->validate([
            'current_password' => ['required', 'string'],
            'password' => ['required', 'confirmed', PasswordRule::defaults()],
        ]);

        if (! Hash::check($data['current_password'], $request->user()->password)) {
            return ApiResponse::error('invalid_password', 'Your current password is not correct.', [], 422);
        }

        $request->user()->update(['password' => $data['password']]);

        // Every other session is invalidated when the password changes.
        $currentTokenId = $request->user()->currentAccessToken()?->id;
        $request->user()->tokens()
            ->when($currentTokenId, fn ($q) => $q->where('id', '!=', $currentTokenId))
            ->delete();

        $this->audit->record(action: 'auth.password_changed', entity: $request->user(), summary: 'Password changed');

        return ApiResponse::message('Your password has been changed. Other devices have been signed out.');
    }

    public function forgotPassword(Request $request)
    {
        $request->validate(['email' => ['required', 'email']]);

        $status = Password::sendResetLink($request->only('email'));

        // The same answer either way — we do not confirm which addresses exist.
        return ApiResponse::message(
            'If that email address belongs to a SASA account, a reset link is on its way.',
            ['status' => $status]
        );
    }

    public function resetPassword(Request $request)
    {
        $request->validate([
            'token' => ['required', 'string'],
            'email' => ['required', 'email'],
            'password' => ['required', 'confirmed', PasswordRule::defaults()],
        ]);

        $status = Password::reset(
            $request->only('email', 'password', 'password_confirmation', 'token'),
            function (User $user, string $password) {
                $user->forceFill([
                    'password' => $password,
                    'remember_token' => Str::random(60),
                    'failed_login_attempts' => 0,
                    'locked_until' => null,
                ])->save();

                $user->tokens()->delete();

                $this->audit->record(action: 'auth.password_reset', entity: $user, summary: 'Password reset');
            }
        );

        return $status === Password::PasswordReset
            ? ApiResponse::message('Your password has been reset. You can sign in now.')
            : ApiResponse::error('reset_failed', 'That reset link is no longer valid. Request a new one.', [], 422);
    }

    private function userPayload(User $user): array
    {
        $memberships = $user->activeMemberships()->get();

        return [
            'id' => $user->id,
            'name' => $user->name,
            'email' => $user->email,
            'phone' => $user->phone,
            'job_title' => $user->job_title,
            'locale' => $user->locale,
            'is_system_admin' => (bool) $user->is_system_admin,
            'mfa_enabled' => (bool) $user->mfa_enabled,
            'organisation' => $user->organisation ? [
                'id' => $user->organisation->id,
                'name' => $user->organisation->name,
                'brand_color' => $user->organisation->brand_color,
            ] : null,
            'notification_preferences' => $user->notification_preferences ?? [],
            'projects' => $memberships->map(fn ($membership) => [
                'id' => $membership->project->id,
                'name' => $membership->project->name,
                'code' => $membership->project->code,
                'country' => $membership->project->country,
                'sector' => $membership->project->sector,
                'status' => $membership->project->status,
                'is_default' => (bool) $membership->is_default,
                'role' => [
                    'key' => $membership->role->key,
                    'name' => $membership->role->name,
                    'description' => $membership->role->description,
                ],
                'permissions' => $membership->role->permissionKeys(),
                'handling_groups' => $membership->handling_groups ?? [],
            ])->values(),
        ];
    }
}

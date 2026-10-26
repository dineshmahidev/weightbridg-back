<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\ApiController;
use App\Models\User;
use App\Services\AuthService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class AuthController extends ApiController
{
    public function __construct(private readonly AuthService $auth)
    {
    }

    public function login(Request $request): JsonResponse
    {
        $data = $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required', 'string'],
            'device_name' => ['nullable', 'string', 'max:120'],
        ]);

        $result = $this->auth->login($data['email'], $data['password'], $data['device_name'] ?? null);

        return $this->ok([
            'token' => $result['token'],
            'expires_at' => $result['expires_at']->toIso8601String(),
            'user' => $this->userPayload($result['user']),
        ], 'Login successful');
    }

    public function logout(Request $request): JsonResponse
    {
        $token = (string) $request->attributes->get('bearer_token', '');

        $this->auth->logout($this->user(), $token);

        return $this->ok(null, 'Logged out');
    }

    public function me(): JsonResponse
    {
        return $this->ok($this->userPayload($this->user()->fresh(['role.permissions', 'department', 'companies'])));
    }

    public function updateProfile(Request $request): JsonResponse
    {
        $user = $this->user();

        $data = $request->validate([
            'name' => ['sometimes', 'string', 'max:120'],
            'email' => ['sometimes', 'email', 'max:150', Rule::unique('users', 'email')->ignore($user->id)],
            'mobile' => ['sometimes', 'nullable', 'string', 'max:30'],
            'timezone' => ['sometimes', 'nullable', 'string', 'max:60'],
            'avatar_url' => ['sometimes', 'nullable', 'string', 'max:255'],
        ]);

        if ($request->hasFile('avatar')) {
            $file = $request->file('avatar');
            $filename = 'avatar_' . $user->id . '_' . time() . '.' . $file->getClientOriginalExtension();
            $path = public_path('uploads/avatars');
            if (!is_dir($path)) {
                mkdir($path, 0755, true);
            }
            $file->move($path, $filename);
            $data['avatar_url'] = '/uploads/avatars/' . $filename;
        } elseif ($request->filled('avatar_base64')) {
            $base64 = (string) $request->input('avatar_base64');
            if (preg_match('/^data:image\/(\w+);base64,/', $base64, $type)) {
                $base64 = substr($base64, strpos($base64, ',') + 1);
                $ext = strtolower($type[1]);
                if (!in_array($ext, ['jpg', 'jpeg', 'gif', 'png', 'webp'])) {
                    $ext = 'png';
                }
                $decoded = base64_decode($base64);
                if ($decoded !== false) {
                    $filename = 'avatar_' . $user->id . '_' . time() . '.' . $ext;
                    $path = public_path('uploads/avatars');
                    if (!is_dir($path)) {
                        mkdir($path, 0755, true);
                    }
                    file_put_contents($path . DIRECTORY_SEPARATOR . $filename, $decoded);
                    $data['avatar_url'] = '/uploads/avatars/' . $filename;
                }
            }
        }

        if ($data !== []) {
            $old = $user->only(array_keys($data));
            $user->fill($data)->save();
            $this->audit('user.profile_updated', $user, $old, $data, $user->companies()->first()?->id);
        }

        return $this->ok($this->userPayload($user->fresh(['role.permissions', 'department', 'companies'])), 'Profile updated');
    }

    public function changePassword(Request $request): JsonResponse
    {
        $data = $request->validate([
            'current_password' => ['required', 'string'],
            'password' => ['required', 'string', 'min:8', 'confirmed'],
        ]);

        $this->auth->changePassword($this->user(), $data['current_password'], $data['password']);

        return $this->ok(null, 'Password changed. Please login again.');
    }

    public function forgotPassword(Request $request): JsonResponse
    {
        $data = $request->validate([
            'email' => ['required', 'email'],
        ]);

        $token = $this->auth->forgotPassword($data['email']);

        $payload = ['message' => 'If that email address exists in our system, a password reset link has been sent.'];

        if ($token && config('app.debug')) {
            // Local development convenience: surface the token so the app flow can be completed.
            $payload['reset_token'] = $token;
            $payload['email'] = $data['email'];
        }

        return $this->ok($payload, 'Password reset requested');
    }

    public function resetPassword(Request $request): JsonResponse
    {
        $data = $request->validate([
            'token' => ['required', 'string'],
            'email' => ['required', 'email'],
            'password' => ['required', 'string', 'min:8', 'confirmed'],
        ]);

        $this->auth->resetPassword($data['email'], $data['token'], $data['password']);

        return $this->ok(null, 'Password has been reset. You can now login.');
    }

    public function forgotPasswordLegacy(Request $request): JsonResponse
    {
        $data = $request->validate(['email' => ['required', 'email']]);

        try {
            Password::sendResetLink($data);
        } catch (\Throwable) {
            // Silently ignore — do not leak whether the account exists.
        }

        return $this->ok(['message' => 'If that email address exists in our system, a password reset link has been sent.']);
    }

    private function userPayload(User $user): array
    {
        $user->loadMissing(['role.permissions', 'department', 'companies']);

        return [
            'id' => $user->id,
            'name' => $user->name,
            'email' => $user->email,
            'mobile' => $user->mobile,
            'avatar_url' => $user->avatar_url,
            'status' => $user->status,
            'timezone' => $user->timezone,
            'last_login_at' => $user->last_login_at?->toIso8601String(),
            'role' => $user->role ? [
                'id' => $user->role->id,
                'name' => $user->role->name,
                'slug' => $user->role->slug,
                'permissions' => $user->role->permissions->pluck('slug')->values(),
            ] : null,
            'department' => $user->department ? [
                'id' => $user->department->id,
                'name' => $user->department->name,
                'company_id' => $user->department->company_id,
            ] : null,
            'companies' => $user->companies->map(fn ($c) => [
                'id' => $c->id,
                'name' => $c->name,
                'code' => $c->code,
                'is_active' => $c->is_active,
                'is_primary' => (bool) $c->pivot->is_primary,
            ])->values(),
            'is_super_admin' => $user->isSuperAdmin(),
        ];
    }
}

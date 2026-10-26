<?php

namespace App\Services;

use App\Exceptions\ApiException;
use App\Models\User;
use App\Models\UserToken;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class AuthService
{
    public const TOKEN_TTL_HOURS = 168;

    public function __construct(private readonly AuditLogger $audit)
    {
    }

    /**
     * @return array{user: User, token: string, expires_at: \Carbon\Carbon}
     */
    public function login(string $email, string $password, ?string $deviceName = null): array
    {
        $user = User::query()
            ->with(['role.permissions', 'department', 'companies'])
            ->where('email', $email)
            ->first();

        if (! $user || ! $user->verifyPassword($password)) {
            throw ApiException::unauthorized('Invalid credentials');
        }

        if (! $user->isActive()) {
            throw ApiException::forbidden('Your account has been deactivated. Contact administrator.');
        }

        [$token, $expiresAt] = $this->issueToken($user, $deviceName);

        $user->forceFill(['last_login_at' => now()])->saveQuietly();

        $this->audit->log('auth.login', $user, null, ['email' => $user->email], null, $user->id);

        return [
            'user' => $user->fresh(['role.permissions', 'department', 'companies']),
            'token' => $token,
            'expires_at' => $expiresAt,
        ];
    }

    /**
     * @return array{0: string, 1: \Carbon\Carbon}
     */
    public function issueToken(User $user, ?string $deviceName = null, ?string $ip = null, ?string $agent = null): array
    {
        $plain = 'wb_'.Str::random(64);
        $expiresAt = now()->addHours((int) config('weighbridge.token_ttl_hours', self::TOKEN_TTL_HOURS));

        UserToken::query()->create([
            'user_id' => $user->id,
            'name' => $deviceName ?: 'mobile-app',
            'token_hash' => hash('sha256', $plain),
            'expires_at' => $expiresAt,
            'ip_address' => $ip,
            'user_agent' => $agent,
        ]);

        return [$plain, $expiresAt];
    }

    public function resolveToken(string $plain): ?User
    {
        if (! str_starts_with($plain, 'wb_')) {
            return null;
        }

        $record = UserToken::query()
            ->with('user.role.permissions')
            ->where('token_hash', hash('sha256', $plain))
            ->first();

        if (! $record || $record->expires_at->isPast()) {
            return null;
        }

        $user = $record->user;

        if (! $user || ! $user->isActive()) {
            return null;
        }

        $record->forceFill(['last_used_at' => now()])->saveQuietly();

        return $user->loadMissing(['role.permissions', 'department', 'companies']);
    }

    public function logout(User $user, string $plainToken): void
    {
        UserToken::query()
            ->where('user_id', $user->id)
            ->where('token_hash', hash('sha256', $plainToken))
            ->delete();

        $this->audit->log('auth.logout', $user, null, null, null, $user->id);
    }

    public function logoutAll(User $user): int
    {
        return UserToken::query()->where('user_id', $user->id)->delete();
    }

    public function changePassword(User $user, string $current, string $new): void
    {
        if (! $user->verifyPassword($current)) {
            throw ApiException::unprocessable('Current password is incorrect');
        }

        $this->setPassword($user, $new);

        $this->audit->log('auth.password_changed', $user, null, null, null, $user->id);

        // Force re-login on other devices after a password change.
        UserToken::query()
            ->where('user_id', $user->id)
            ->where('token_hash', '!=', '')
            ->delete();
    }

    public function setPassword(User $user, string $password): void
    {
        DB::transaction(function () use ($user, $password) {
            $user->forceFill(['password' => $password])->save();
        });
    }

    /**
     * Local-friendly password reset: returns a one-time token that the
     * frontend can submit to complete the reset. In production this same
     * token is also emailed through Laravel's notification system.
     */
    public function forgotPassword(string $email): ?string
    {
        $user = User::query()->where('email', $email)->first();

        if (! $user) {
            return null;
        }

        $token = Str::random(64);

        cache()->put(
            'password.reset.'.$user->id,
            ['token' => hash('sha256', $token), 'expires' => now()->addHour()->timestamp],
            now()->addHour()
        );

        $this->audit->log('auth.password_reset_requested', $user, null, null, null, $user->id);

        return $token;
    }

    public function resetPassword(string $email, string $token, string $password): void
    {
        $user = User::query()->where('email', $email)->first();
        $cached = $user ? cache()->get('password.reset.'.$user->id) : null;

        if (! $user || ! $cached || ($cached['expires'] ?? 0) < now()->timestamp) {
            throw ApiException::unprocessable('Reset token is invalid or expired');
        }

        if (! hash_equals($cached['token'], hash('sha256', $token))) {
            throw ApiException::unprocessable('Reset token is invalid or expired');
        }

        $this->setPassword($user, $password);
        cache()->forget('password.reset.'.$user->id);
        UserToken::query()->where('user_id', $user->id)->delete();

        $this->audit->log('auth.password_reset', $user, null, null, null, $user->id);
    }
}

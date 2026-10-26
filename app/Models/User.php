<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Facades\Hash;

class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable;

    protected $fillable = [
        'name',
        'email',
        'mobile',
        'password',
        'role_id',
        'department_id',
        'status',
        'last_login_at',
        'timezone',
        'avatar_url',
    ];

    protected $hidden = [
        'password',
        'remember_token',
    ];

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'last_login_at' => 'datetime',
            'password' => 'hashed',
        ];
    }

    public function role(): BelongsTo
    {
        return $this->belongsTo(Role::class);
    }

    public function department(): BelongsTo
    {
        return $this->belongsTo(Department::class);
    }

    public function companies(): BelongsToMany
    {
        return $this->belongsToMany(Company::class, 'user_company')->withPivot('is_primary')->withTimestamps();
    }

    public function tokens(): HasMany
    {
        return $this->hasMany(UserToken::class);
    }

    public function deviceTokens(): HasMany
    {
        return $this->hasMany(UserDeviceToken::class);
    }

    public function isActive(): bool
    {
        return $this->status === 'active';
    }

    public function isSuperAdmin(): bool
    {
        return $this->role?->slug === 'super_admin';
    }

    public function isAdmin(): bool
    {
        return in_array($this->role?->slug, ['super_admin', 'company_admin'], true);
    }

    /**
     * Custom permission check used across the API.
     * Supports pipe-separated abilities: "reports.view|reports.export".
     */
    public function hasPermission(string $permission): bool
    {
        if (! $this->isActive()) {
            return false;
        }

        if ($this->isSuperAdmin()) {
            return true;
        }

        return $this->role?->hasPermission($permission) ?? false;
    }

    public function can($abilities, $arguments = []): bool
    {
        if (is_string($abilities)) {
            foreach (array_filter(array_map('trim', explode('|', $abilities))) as $ability) {
                if ($this->hasPermission($ability)) {
                    return true;
                }
            }

            return false;
        }

        return parent::can($abilities, $arguments);
    }

    /**
     * Company ids this user may access. Null array key "all" is not used;
     * an empty array means no company access (super admin gets all).
     *
     * @return array<int, int>
     */
    public function accessibleCompanyIds(): array
    {
        if ($this->isSuperAdmin()) {
            return Company::query()->pluck('id')->all();
        }

        return $this->companies()->pluck('companies.id')->all();
    }

    public function belongsToCompany(int $companyId): bool
    {
        if ($this->isSuperAdmin()) {
            return true;
        }

        return $this->companies()->where('companies.id', $companyId)->exists();
    }

    public function verifyPassword(string $password): bool
    {
        return Hash::check($password, $this->password);
    }
}

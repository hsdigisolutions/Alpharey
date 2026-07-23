<?php

namespace App\Models;

use App\Enums\UserRole;
use App\Models\Concerns\Auditable;
use App\Notifications\BilingualResetPassword;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property string $name
 * @property string $email
 * @property UserRole $role
 * @property int|null $company_id
 * @property string $locale
 * @property bool $active
 * @property string|null $two_factor_secret
 * @property list<string>|null $two_factor_recovery_codes
 * @property Carbon|null $two_factor_confirmed_at
 * @property Carbon|null $password_reset_requested_at
 */
class User extends Authenticatable
{
    use Auditable;

    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable;

    public string $auditModule = 'users';

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'email',
        'password',
        'role',
        'company_id',
        'locale',
        'active',
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var list<string>
     */
    protected $hidden = [
        'password',
        'remember_token',
        // Second-factor secrets — never serialised, never audited.
        'two_factor_secret',
        'two_factor_recovery_codes',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'role' => UserRole::class,
            'active' => 'boolean',
            'two_factor_secret' => 'encrypted',
            'two_factor_recovery_codes' => 'encrypted:array',
            'two_factor_confirmed_at' => 'datetime',
            'password_reset_requested_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Company, $this>
     */
    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    /**
     * @return HasMany<UserModulePermission, $this>
     */
    public function modulePermissions(): HasMany
    {
        return $this->hasMany(UserModulePermission::class);
    }

    public function isSuperAdmin(): bool
    {
        return $this->role === UserRole::SuperAdmin;
    }

    public function sendPasswordResetNotification($token): void
    {
        $this->notify(new BilingualResetPassword($token));
    }

    public function isCompanyAdmin(): bool
    {
        return $this->role === UserRole::CompanyAdmin;
    }

    public function isWorker(): bool
    {
        return $this->role === UserRole::Worker;
    }

    /**
     * The workforce record this login belongs to — set only for worker
     * accounts, which is what lets a phone punch land on the right payslip.
     *
     * @return HasOne<Employee, $this>
     */
    public function employee(): HasOne
    {
        return $this->hasOne(Employee::class);
    }
}

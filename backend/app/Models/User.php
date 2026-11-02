<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class User extends Model
{
    public const PASSWORD_ATTRIBUTES = ['password', 'password_algo', 'password_salt'];

    protected $hidden = self::PASSWORD_ATTRIBUTES;

    protected static function booted(): void
    {
        static::creating(static function (User $user): void {
            $limit = \App\Services\SubscriptionGraceService::VIRTUAL_BASE;
            if ((int) $user->id >= $limit || ($user->id === null && (int) User::max('id') >= $limit - 1)) {
                throw new \RuntimeException('Real user IDs exhausted the range reserved below virtual identities');
            }
        });
    }

    public const CONNECTIVITY_TEST_EMAIL = 'system-connectivity-test@panelcore.invalid';

    public static function connectivityTestEmail(int $machineId): string
    {
        return 'system-connectivity-test+machine-' . $machineId . '@panelcore.invalid';
    }

    public static function isConnectivityTestEmail(string $email): bool
    {
        $email = strtolower(trim($email));
        return $email === self::CONNECTIVITY_TEST_EMAIL
            || (str_starts_with($email, 'system-connectivity-test+machine-')
                && str_ends_with($email, '@panelcore.invalid'));
    }

    public function scopeWithoutConnectivityTests($query)
    {
        return $query->where('email', '!=', self::CONNECTIVITY_TEST_EMAIL)
            ->where('email', 'not like', 'system-connectivity-test+machine-%@panelcore.invalid');
    }

    use Concerns\GuardsPlanReferences;

    protected $table = 'v2_user';
    protected $dateFormat = 'U';
    protected $guarded = ['id'];
    protected $casts = [
        'created_at' => 'timestamp',
        'updated_at' => 'timestamp'
    ];

    public function plan()
    {
        return $this->belongsTo(Plan::class);
    }

    public function invite_user()
    {
        return $this->belongsTo(User::class, 'invite_user_id');
    }

    public function group()
    {
        return $this->belongsTo(ServerGroup::class, 'group_id');
    }

    public function scopeByEmail($query, string $email)
    {
        return $query->where('email', trim($email));
    }
}

<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Order extends Model
{
    use Concerns\GuardsPlanReferences;

    public const TYPE_NEW_PURCHASE = 1;
    public const TYPE_RENEWAL = 2;
    public const TYPE_UPGRADE = 3;
    public const TYPE_RESET_TRAFFIC = 4;

    protected $table = 'v2_order';
    protected $dateFormat = 'U';
    protected $guarded = ['id'];
    protected $casts = [
        'created_at' => 'timestamp',
        'updated_at' => 'timestamp',
        'surplus_order_ids' => 'array'
    ];

    public function plan()
    {
        return $this->belongsTo(Plan::class);
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function invite_user()
    {
        return $this->belongsTo(User::class, 'invite_user_id');
    }

    public function commission_log()
    {
        return $this->hasMany(CommissionLog::class, 'trade_no', 'trade_no');
    }
}

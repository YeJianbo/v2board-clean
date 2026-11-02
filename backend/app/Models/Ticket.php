<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Ticket extends Model
{
    public const STATUS_OPEN = 0;
    public const STATUS_CLOSED = 1;
    public const REPLY_PENDING = 0;
    public const REPLY_REPLIED = 1;
    protected $table = 'v2_ticket';
    protected $dateFormat = 'U';
    protected $guarded = ['id'];
    protected $casts = [
        'created_at' => 'timestamp',
        'updated_at' => 'timestamp'
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function messages(): HasMany
    {
        return $this->hasMany(TicketMessage::class, 'ticket_id')->orderBy('id', 'asc');
    }

    public function scopePendingReply(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_OPEN)->where('reply_status', self::REPLY_PENDING);
    }
}

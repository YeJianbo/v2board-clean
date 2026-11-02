<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use App\Exceptions\ModelDeletionRejected;
use App\Exceptions\PlanInUseException;
use Illuminate\Support\Facades\Schema;

class Plan extends Model
{
    use Concerns\GuardsGroupReferences;

    public const RESET_TRAFFIC_FOLLOW_SYSTEM = null;
    public const RESET_TRAFFIC_FIRST_DAY_MONTH = 0;
    public const RESET_TRAFFIC_MONTHLY = 1;
    public const RESET_TRAFFIC_NEVER = 2;
    public const RESET_TRAFFIC_FIRST_DAY_YEAR = 3;
    public const RESET_TRAFFIC_YEARLY = 4;

    protected $table = 'v2_plan';
    protected $dateFormat = 'U';
    protected $guarded = ['id'];
    protected $casts = [
        'created_at' => 'timestamp',
        'updated_at' => 'timestamp'
    ];

    public function users()
    {
        return $this->hasMany(User::class);
    }

    public function group()
    {
        return $this->belongsTo(ServerGroup::class, 'group_id');
    }

    public function delete()
    {
        if (!$this->exists) return parent::delete();
        try {
            return $this->getConnection()->transaction(function () {
                $result = parent::delete();
                if ($result === false) throw new ModelDeletionRejected();
                return $result;
            }, 3);
        } catch (ModelDeletionRejected $exception) {
            return false;
        }
    }

    protected function performDeleteOnModel()
    {
        $this->newModelQuery()->whereKey($this->getKey())->lockForUpdate()->firstOrFail();
        // These locking reads also see newly committed references under REPEATABLE READ.
        foreach (['orders' => new Order(), 'users' => new User()] as $kind => $reference) {
            $reference->setConnection($this->getConnectionName());
            if ($reference->newQuery()->where('plan_id', $this->getKey())->sharedLock()->first(['id'])) {
                throw new PlanInUseException($kind);
            }
        }
        if (Schema::connection($this->getConnectionName())->hasTable('v2_giftcard')) {
            $giftcard = new Giftcard();
            $giftcard->setConnection($this->getConnectionName());
            if ($giftcard->newQuery()->where('plan_id', $this->getKey())->sharedLock()->first(['id'])) {
                throw new PlanInUseException('giftcards');
            }
        }
        parent::performDeleteOnModel();
    }
}

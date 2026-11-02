<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use App\Exceptions\GroupInUseException;
use App\Exceptions\ModelDeletionRejected;
use App\Services\ServerGroupUsage;
use Throwable;

class ServerGroup extends Model
{
    protected $table = 'v2_server_group';
    protected $dateFormat = 'U';
    protected $casts = [
        'created_at' => 'timestamp',
        'updated_at' => 'timestamp'
    ];

    public function users()
    {
        return $this->hasMany(User::class, 'group_id');
    }

    public function delete()
    {
        if (!$this->exists) return parent::delete();
        try {
            return $this->getConnection()->transaction(function () {
                // A rolled-back attempt may already have changed the in-memory model state.
                $this->exists = true;
                $result = parent::delete();
                if ($result === false) throw new ModelDeletionRejected();
                return $result;
            }, 3);
        } catch (ModelDeletionRejected $exception) {
            $this->exists = true;
            return false;
        } catch (Throwable $exception) {
            $this->exists = true;
            throw $exception;
        }
    }

    protected function performDeleteOnModel()
    {
        $this->newModelQuery()->whereKey($this->getKey())->lockForUpdate()->firstOrFail();
        // Locking reads see committed references even if an outer RR transaction has an old snapshot.
        if (app(ServerGroupUsage::class)->contains((int) $this->getKey(), $this->getConnectionName(), true)) {
            throw new GroupInUseException('nodes');
        }
        foreach (['plans' => new Plan(), 'users' => new User()] as $kind => $reference) {
            $reference->setConnection($this->getConnectionName());
            if ($reference->newQuery()->where('group_id', $this->getKey())->sharedLock()->first(['id'])) {
                throw new GroupInUseException($kind);
            }
        }
        parent::performDeleteOnModel();
    }
}

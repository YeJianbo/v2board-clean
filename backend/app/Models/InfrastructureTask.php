<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Crypt;

class InfrastructureTask extends Model
{
    protected $table = 'v2_infra_task';
    protected $dateFormat = 'U';
    public $incrementing = false;
    protected $keyType = 'string';
    protected $guarded = [];
    protected $hidden = ['encrypted_payload'];
    protected $casts = ['result' => 'array', 'expires_at' => 'integer', 'dispatched_at' => 'integer',
        'completed_at' => 'integer', 'created_at' => 'timestamp', 'updated_at' => 'timestamp'];

    public function payload(): array
    {
        return json_decode(Crypt::decryptString($this->encrypted_payload), true, 512, JSON_THROW_ON_ERROR);
    }
}

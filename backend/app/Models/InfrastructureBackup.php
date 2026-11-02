<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Crypt;

class InfrastructureBackup extends Model
{
    protected $table = 'v2_infra_backup';
    protected $dateFormat = 'U';
    public $incrementing = false;
    protected $keyType = 'string';
    protected $guarded = [];
    protected $hidden = ['encrypted_password'];
    protected $casts = ['bytes' => 'integer', 'expected_bytes' => 'integer', 'created_at' => 'timestamp', 'updated_at' => 'timestamp'];

    public function path(): string
    {
        return storage_path('app/infrastructure/backups/' . $this->id . '.enc');
    }

    public function password(): string
    {
        return Crypt::decryptString($this->encrypted_password);
    }
}

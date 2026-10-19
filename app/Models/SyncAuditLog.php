<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SyncAuditLog extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'entity_name',
        'entity_id',
        'action_type',
        'changed_by',
        'old_values',
        'new_values',
        'client_version',
        'server_version',
        'ip_address',
        'created_at',
    ];

    protected $casts = [
        'old_values' => 'array',
        'new_values' => 'array',
        'client_version' => 'integer',
        'server_version' => 'integer',
        'created_at' => 'datetime',
    ];
}

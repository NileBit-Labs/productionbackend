<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SyncEvent extends Model
{
    protected $fillable = [
        'shop_id', 'user_id', 'device_id', 'local_event_id', 'entity_type', 'operation',
        'payload_hash', 'status', 'entity_id', 'result', 'processed_at',
    ];

    protected function casts(): array
    {
        return [
            'result' => 'array',
            'processed_at' => 'datetime',
        ];
    }
}

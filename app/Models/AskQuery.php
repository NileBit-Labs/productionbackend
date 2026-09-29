<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AskQuery extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = [
        'shop_id', 'user_id', 'question', 'answer', 'tools', 'input_tokens', 'output_tokens',
        'duration_ms', 'status', 'error', 'counts_toward_limit',
    ];

    protected function casts(): array
    {
        return ['tools' => 'array', 'counts_toward_limit' => 'boolean'];
    }
}

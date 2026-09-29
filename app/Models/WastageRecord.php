<?php

namespace App\Models;

use App\Enums\WastageStage;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WastageRecord extends Model
{
    protected $fillable = [
        'shop_id', 'product_id', 'production_batch_id', 'stage', 'quantity', 'unit_cost', 'total_cost',
        'reason', 'wastage_date', 'stock_movement_id', 'recorded_by', 'idempotency_key',
    ];

    protected function casts(): array
    {
        return [
            'stage' => WastageStage::class,
            'quantity' => 'float',
            'unit_cost' => 'integer',
            'total_cost' => 'integer',
            'wastage_date' => 'date:Y-m-d',
        ];
    }

    /**
     * Losses that still count: standalone ones, and those of batches that were not cancelled
     * (a cancelled batch put its wasted stock back).
     */
    public function scopeCountable(Builder $query): void
    {
        $query->where(fn ($q) => $q->whereNull('wastage_records.production_batch_id')
            ->orWhereIn('wastage_records.production_batch_id', ProductionBatch::select('id')->where('status', '!=', 'cancelled')));
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function batch(): BelongsTo
    {
        return $this->belongsTo(ProductionBatch::class, 'production_batch_id');
    }

    public function recorder(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recorded_by');
    }
}

<?php

namespace App\Models;

use App\Enums\BatchStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ProductionBatch extends Model
{
    protected $attributes = ['status' => 'draft'];

    protected $fillable = [
        'shop_id', 'batch_number', 'recipe_id', 'name', 'status', 'production_date', 'expiry_date',
        'planned_yield', 'yield_unit', 'responsible_user_id', 'notes',
        'material_cost', 'packaging_cost', 'labour_cost', 'direct_expense_cost', 'wastage_cost', 'total_cost', 'output_quantity',
        'created_by', 'completed_at', 'completed_by', 'cancelled_at', 'cancelled_by', 'cancel_reason', 'idempotency_key',
    ];

    protected function casts(): array
    {
        return [
            'status' => BatchStatus::class,
            'production_date' => 'date:Y-m-d',
            'expiry_date' => 'date:Y-m-d',
            'planned_yield' => 'float',
            'output_quantity' => 'float',
            'material_cost' => 'integer',
            'packaging_cost' => 'integer',
            'labour_cost' => 'integer',
            'direct_expense_cost' => 'integer',
            'wastage_cost' => 'integer',
            'total_cost' => 'integer',
            'completed_at' => 'datetime',
            'cancelled_at' => 'datetime',
        ];
    }

    public function recipe(): BelongsTo
    {
        return $this->belongsTo(Recipe::class);
    }

    public function inputs(): HasMany
    {
        return $this->hasMany(ProductionBatchInput::class)->orderBy('id');
    }

    public function outputs(): HasMany
    {
        return $this->hasMany(ProductionBatchOutput::class)->orderBy('id');
    }

    public function wastage(): HasMany
    {
        return $this->hasMany(WastageRecord::class)->orderBy('id');
    }

    public function expenses(): HasMany
    {
        return $this->hasMany(Expense::class)->orderBy('id');
    }

    public function responsible(): BelongsTo
    {
        return $this->belongsTo(User::class, 'responsible_user_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function completer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'completed_by');
    }
}

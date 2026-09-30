<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ProductionLot extends Model
{
    protected $fillable = ['shop_id', 'product_id', 'production_batch_id', 'production_batch_output_id', 'produced_quantity', 'production_date', 'expiry_date'];

    protected function casts(): array
    {
        return ['produced_quantity' => 'float', 'production_date' => 'date:Y-m-d', 'expiry_date' => 'date:Y-m-d'];
    }

    public function product(): BelongsTo { return $this->belongsTo(Product::class); }
    public function batch(): BelongsTo { return $this->belongsTo(ProductionBatch::class, 'production_batch_id'); }
    public function output(): BelongsTo { return $this->belongsTo(ProductionBatchOutput::class, 'production_batch_output_id'); }
    public function movements(): HasMany { return $this->hasMany(ProductionLotMovement::class); }
}

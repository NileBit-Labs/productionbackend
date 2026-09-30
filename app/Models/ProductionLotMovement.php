<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProductionLotMovement extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = ['production_lot_id', 'quantity_delta', 'movement_type', 'reference_type', 'reference_id', 'reason', 'performed_by'];

    protected function casts(): array { return ['quantity_delta' => 'float']; }
    public function lot(): BelongsTo { return $this->belongsTo(ProductionLot::class, 'production_lot_id'); }
}

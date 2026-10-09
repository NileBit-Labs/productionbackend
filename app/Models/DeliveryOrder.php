<?php

namespace App\Models;

use App\Services\CustomerDebt;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DeliveryOrder extends Model
{
    protected $appends = ['outstanding'];

    public function getOutstandingAttribute(): int
    {
        $sale = $this->sale;
        if (! $sale || ! $sale->customer_id || $sale->status !== 'completed') {
            return 0;
        }

        return (int) (collect(app(CustomerDebt::class)->openSales([$sale->customer_id])[$sale->customer_id] ?? [])->firstWhere('sale_id', $sale->id)['owed'] ?? 0);
    }

    protected $fillable = ['shop_id', 'sale_id', 'fulfillment_type', 'status', 'recipient_name', 'recipient_phone', 'address', 'location_notes', 'instructions', 'notes', 'requested_at', 'driver_name', 'driver_phone', 'dispatched_at', 'completed_at', 'proof_of_delivery', 'failure_reason'];

    protected function casts(): array
    {
        return ['requested_at' => 'datetime', 'dispatched_at' => 'datetime', 'completed_at' => 'datetime'];
    }

    public function sale(): BelongsTo
    {
        return $this->belongsTo(Sale::class);
    }
}

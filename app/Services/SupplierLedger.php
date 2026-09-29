<?php

namespace App\Services;

use App\Models\Supplier;
use App\Models\SupplierLedgerEntry;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;

class SupplierLedger
{
    public const PURCHASE = 'PURCHASE';

    public const PAYMENT = 'PAYMENT';

    public const PURCHASE_CANCEL = 'PURCHASE_CANCEL';

    public function balance(Supplier $supplier): int
    {
        return (int) SupplierLedgerEntry::query()
            ->where('supplier_id', $supplier->id)
            ->sum('amount');
    }

    /** Positive amounts increase what the shop owes; negative amounts reduce it. */
    public function record(Supplier $supplier, string $type, int $amount, User $by, ?Model $reference = null, ?string $note = null): SupplierLedgerEntry
    {
        return SupplierLedgerEntry::create([
            'shop_id' => $supplier->shop_id,
            'supplier_id' => $supplier->id,
            'type' => $type,
            'amount' => $amount,
            'reference_type' => $reference ? $reference::class : null,
            'reference_id' => $reference?->getKey(),
            'note' => $note,
            'recorded_by' => $by->id,
        ]);
    }
}

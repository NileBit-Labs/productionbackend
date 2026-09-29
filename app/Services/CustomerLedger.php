<?php

namespace App\Services;

use App\Models\Customer;
use App\Models\CustomerLedgerEntry;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;

/**
 * The only writer of customer balances. A balance is never stored: it is the
 * sum of the append-only ledger, so it can always be re-derived and audited.
 */
class CustomerLedger
{
    public const CREDIT_SALE = 'CREDIT_SALE';

    public const PAYMENT = 'PAYMENT';

    public const SALE_VOID = 'SALE_VOID';

    public const REFUND = 'REFUND';

    public function balance(Customer $customer): int
    {
        return (int) CustomerLedgerEntry::where('customer_id', $customer->id)->sum('amount');
    }

    /** @param  int  $amount  positive = the customer owes more, negative = they owe less */
    public function record(Customer $customer, string $type, int $amount, User $by, ?Model $reference = null, ?string $note = null): CustomerLedgerEntry
    {
        return CustomerLedgerEntry::create([
            'shop_id' => $customer->shop_id,
            'customer_id' => $customer->id,
            'type' => $type,
            'amount' => $amount,
            'reference_type' => $reference ? $reference::class : null,
            'reference_id' => $reference?->getKey(),
            'note' => $note,
            'recorded_by' => $by->id,
        ]);
    }
}

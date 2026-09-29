<?php

namespace App\Support;

use App\Models\SaleItem;
use Illuminate\Support\Collection;

/**
 * Spreads a sale's order-level discount across its lines so each line has a
 * real value: what was actually paid for it. Refunds and reports both need
 * this, and they must agree to the shilling, so the rule lives here once.
 *
 * The shares are whole shillings that add up exactly to the discount (any
 * remainder goes one shilling at a time to the earliest lines).
 */
class DiscountAllocation
{
    /**
     * @param  Collection<int, SaleItem>  $items
     * @param  int  $saleDiscount  the sale's total discount (line discounts included)
     * @return array<int, int> sale item id => net value
     */
    public static function netValues(Collection $items, int $saleDiscount): array
    {
        $items = $items->sortBy('id');
        $orderDiscount = max(0, $saleDiscount - (int) $items->sum('discount'));
        $lineSum = (int) $items->sum('line_total');

        $share = [];
        $left = $orderDiscount;

        foreach ($items as $item) {
            $share[$item->id] = $lineSum > 0 ? intdiv($orderDiscount * $item->line_total, $lineSum) : 0;
            $left -= $share[$item->id];
        }

        foreach ($items as $item) {
            if ($left <= 0) {
                break;
            }
            $share[$item->id]++;
            $left--;
        }

        return $items->mapWithKeys(fn (SaleItem $item) => [$item->id => $item->line_total - $share[$item->id]])->all();
    }
}

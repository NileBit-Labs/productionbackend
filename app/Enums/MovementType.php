<?php

namespace App\Enums;

enum MovementType: string
{
    case OpeningStock = 'OPENING_STOCK';
    case Sale = 'SALE';
    case Purchase = 'PURCHASE';
    case SaleReturn = 'SALE_RETURN';
    case PurchaseReturn = 'PURCHASE_RETURN';
    case Damage = 'DAMAGE';
    case Loss = 'LOSS';
    case Adjustment = 'ADJUSTMENT';
}

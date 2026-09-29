<?php

namespace App\Enums;

enum ProductKind: string
{
    case RawMaterial = 'raw_material';
    case Packaging = 'packaging';
    case FinishedGood = 'finished_good';

    /** Only finished goods are rung up at the till. */
    public function isSellable(): bool
    {
        return $this === self::FinishedGood;
    }
}

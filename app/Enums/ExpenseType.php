<?php

namespace App\Enums;

/**
 * Operating expenses (rent, marketing, general electricity) reduce profit in the period they
 * are dated. Direct ones are allocated to one production batch: they become part of what that
 * batch's output cost, and so reach profit through the cost of goods sold instead.
 */
enum ExpenseType: string
{
    case Operating = 'operating';
    case DirectLabour = 'direct_labour';
    case DirectProduction = 'direct_production';

    public function isDirect(): bool
    {
        return $this !== self::Operating;
    }
}

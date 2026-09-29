<?php

namespace App\Services;

use RuntimeException;

/** Thrown inside the sync transaction to roll a sale back when prices moved. */
class PriceChanged extends RuntimeException
{
    public function __construct(public int $serverTotal, public int $expected)
    {
        parent::__construct('Prices changed since the sale was recorded.');
    }
}

<?php

namespace App\Enums;

enum PaymentMethod: string
{
    case Cash = 'CASH';
    case MobileMoney = 'MOBILE_MONEY';
    case Card = 'CARD';
    case Bank = 'BANK';
    case Other = 'OTHER';
}

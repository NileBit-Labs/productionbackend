<?php

namespace App\Enums;

enum WastageStage: string
{
    case RawMaterial = 'raw_material';
    case Packaging = 'packaging';
    case Production = 'production';
    case FinishedGoods = 'finished_goods';
}

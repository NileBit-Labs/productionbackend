<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class MeasurementUnit extends Model
{
    /** What a new shop starts with; each shop can add its own. */
    public const DEFAULTS = [
        ['name' => 'Kilogram', 'symbol' => 'kg', 'dimension' => 'mass'],
        ['name' => 'Gram', 'symbol' => 'g', 'dimension' => 'mass'],
        ['name' => 'Litre', 'symbol' => 'L', 'dimension' => 'volume'],
        ['name' => 'Millilitre', 'symbol' => 'ml', 'dimension' => 'volume'],
        ['name' => 'Piece', 'symbol' => 'pcs', 'dimension' => 'count'],
        ['name' => 'Pack', 'symbol' => 'pack', 'dimension' => 'count'],
        ['name' => 'Carton', 'symbol' => 'carton', 'dimension' => 'count'],
        ['name' => 'Bag', 'symbol' => 'bag', 'dimension' => 'count'],
    ];

    public const DIMENSIONS = ['mass', 'volume', 'count', 'length', 'other'];

    protected $fillable = ['shop_id', 'name', 'symbol', 'dimension'];
}

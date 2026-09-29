<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class RecipeItem extends Model
{
    protected $fillable = ['recipe_id', 'product_id', 'quantity', 'note'];

    protected function casts(): array
    {
        return ['quantity' => 'float'];
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }
}

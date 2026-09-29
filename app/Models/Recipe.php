<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Recipe extends Model
{
    protected $fillable = ['shop_id', 'name', 'family', 'yield_quantity', 'yield_unit', 'instructions', 'status', 'created_by'];

    protected function casts(): array
    {
        return ['yield_quantity' => 'float'];
    }

    public function items(): HasMany
    {
        return $this->hasMany(RecipeItem::class)->orderBy('id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}

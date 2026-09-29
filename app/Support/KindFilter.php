<?php

namespace App\Support;

use App\Enums\ProductKind;
use Illuminate\Http\Request;

/** Reads ?kind=raw_material or ?kind=raw_material,packaging; unknown values are ignored. */
final class KindFilter
{
    /** @return array<int, string> */
    public static function from(Request $request): array
    {
        $asked = array_filter(array_map('trim', explode(',', (string) $request->query('kind', ''))));

        return array_values(array_filter($asked, fn (string $kind) => ProductKind::tryFrom($kind) !== null));
    }
}

<?php

namespace App\Support;

use Illuminate\Http\Request;

/**
 * How many rows a list page shows. The screen offers a "rows per page" choice; anything else
 * (a typo, a huge number asked for by hand) falls back to the list's own default rather than
 * letting one request pull the whole table.
 */
final class PerPage
{
    public const ALLOWED = [10, 25, 50, 100];

    public static function from(Request $request, int $default = 50): int
    {
        $asked = $request->integer('per_page');

        return in_array($asked, self::ALLOWED, true) ? $asked : $default;
    }
}

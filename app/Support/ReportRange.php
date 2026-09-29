<?php

namespace App\Support;

use App\Models\Shop;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * A run of whole calendar days in the shop's own timezone.
 *
 * Timestamps are stored in UTC but a shopkeeper's "today" is a local
 * notion: a sale at 01:30 in Kampala is stored as 22:30 UTC the day before
 * and must still count towards the day it happened. Everything that reports
 * by date goes through this class so that boundary is handled in one place.
 */
class ReportRange
{
    public const MAX_DAYS = 366;

    public function __construct(
        public readonly CarbonImmutable $from,
        public readonly CarbonImmutable $to,
        public readonly string $timezone,
    ) {}

    public static function timezoneFor(Shop $shop): string
    {
        $tz = $shop->organization?->timezone;

        return $tz && in_array($tz, timezone_identifiers_list(), true) ? $tz : 'Africa/Kampala';
    }

    /**
     * Reads ?from=YYYY-MM-DD&to=YYYY-MM-DD (inclusive, local dates).
     *
     * @param  'today'|'month'  $default  the period used when nothing is asked for
     */
    public static function fromRequest(Request $request, Shop $shop, string $default = 'month'): self
    {
        $tz = self::timezoneFor($shop);
        $now = CarbonImmutable::now($tz);

        $request->validate([
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d'],
        ]);

        $fallbackFrom = $default === 'today' ? $now->startOfDay() : $now->startOfMonth();

        $from = $request->filled('from') ? CarbonImmutable::parse($request->query('from'), $tz)->startOfDay() : $fallbackFrom;
        $to = $request->filled('to') ? CarbonImmutable::parse($request->query('to'), $tz)->startOfDay() : $now->startOfDay();

        if ($from->greaterThan($to)) {
            throw ValidationException::withMessages(['from' => 'The start date must be on or before the end date.']);
        }

        if ($from->diffInDays($to) + 1 > self::MAX_DAYS) {
            throw ValidationException::withMessages(['to' => 'Choose a period of at most a year.']);
        }

        return new self($from, $to, $tz);
    }

    public static function today(Shop $shop): self
    {
        $tz = self::timezoneFor($shop);
        $today = CarbonImmutable::now($tz)->startOfDay();

        return new self($today, $today, $tz);
    }

    public static function lastDays(Shop $shop, int $days): self
    {
        $tz = self::timezoneFor($shop);
        $today = CarbonImmutable::now($tz)->startOfDay();

        return new self($today->subDays($days - 1), $today, $tz);
    }

    /** The first instant of the range, as a UTC timestamp. */
    public function utcFrom(): CarbonImmutable
    {
        return $this->from->startOfDay()->utc();
    }

    /** The last instant of the range, as a UTC timestamp. */
    public function utcTo(): CarbonImmutable
    {
        return $this->to->endOfDay()->utc();
    }

    /**
     * SQL that turns a stored UTC timestamp column into its local calendar
     * date (Y-m-d), for grouping by day inside the database.
     *
     * The zone is checked against the known list in timezoneFor(), so it is
     * safe to place in the statement. SQLite has no timezone database and is
     * only used by tests, so it gets the zone's current fixed offset.
     */
    public function localDateSql(string $column): string
    {
        if (DB::connection()->getDriverName() === 'pgsql') {
            return "to_char(({$column} AT TIME ZONE 'UTC') AT TIME ZONE '{$this->timezone}', 'YYYY-MM-DD')";
        }

        $minutes = intdiv($this->from->setTimezone($this->timezone)->getOffset(), 60);

        return sprintf("date(%s, '%+d minutes')", $column, $minutes);
    }

    /** The local calendar date (Y-m-d) a stored UTC timestamp falls on. */
    public function localDate(CarbonInterface $utc): string
    {
        return CarbonImmutable::instance($utc)->setTimezone($this->timezone)->toDateString();
    }

    /** @return array<int, string> every local date in the range, oldest first */
    public function days(): array
    {
        $days = [];

        for ($day = $this->from; $day->lessThanOrEqualTo($this->to); $day = $day->addDay()) {
            $days[] = $day->toDateString();
        }

        return $days;
    }

    /** @return array{from: string, to: string, timezone: string} */
    public function toArray(): array
    {
        return ['from' => $this->from->toDateString(), 'to' => $this->to->toDateString(), 'timezone' => $this->timezone];
    }
}

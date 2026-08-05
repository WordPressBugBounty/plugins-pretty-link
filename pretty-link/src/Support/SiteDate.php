<?php

declare(strict_types=1);

namespace PrettyLinks\Support;

/**
 * Helpers for UTC-stored DATETIME columns queried with site-local calendar days.
 *
 * Click/link timestamps are stamped with current_time('mysql', true) (UTC).
 * Admin date pickers emit YYYY-MM-DD in the site timezone — convert those
 * day bounds to UTC before comparing against created_at. Date-only chart
 * buckets should GROUP BY site-local calendar periods via sqlSiteLocal().
 */
final class SiteDate
{
    /**
     * Trusted DATETIME column identifiers for sqlSiteLocal().
     * Never pass user input — only these literal identifiers are accepted.
     *
     * @var array<string, true>
     */
    private const SQL_SITE_LOCAL_COLUMNS = [
        'created_at'    => true,
        'cl.created_at' => true,
    ];

    /**
     * Validate a YYYY-MM-DD string as a real calendar day.
     * Returns '' for empty, malformed, or impossible dates (e.g. 2026-13-40).
     *
     * @param  string $raw Candidate date string.
     * @return string The validated YYYY-MM-DD, or '' when unusable.
     */
    public static function normalizeYmd(string $raw): string
    {
        $raw = trim($raw);
        if (preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $raw, $m) !== 1) {
            return '';
        }

        $year  = (int) $m[1];
        $month = (int) $m[2];
        $day   = (int) $m[3];
        if (!checkdate($month, $day, $year)) {
            return '';
        }

        return sprintf('%04d-%02d-%02d', $year, $month, $day);
    }

    /**
     * Today's calendar day in the site timezone.
     */
    public static function todayYmd(): string
    {
        return wp_date('Y-m-d');
    }

    /**
     * Site-local calendar day N days before today (inclusive of today when $days = 0).
     *
     * @param  integer $days Days to go back.
     * @return string YYYY-MM-DD in the site timezone.
     */
    public static function daysAgoYmd(int $days): string
    {
        $days = max(0, $days);

        return (new \DateTimeImmutable(self::todayYmd() . ' 00:00:00', wp_timezone()))
            ->modify('-' . $days . ' days')
            ->format('Y-m-d');
    }

    /**
     * Normalize picker from/to, falling back to the last $defaultDaysBack days.
     * Swaps ends when from > to so callers never get an inverted range.
     *
     * @param  string  $fromRaw         Raw `from` as submitted.
     * @param  string  $toRaw           Raw `to` as submitted.
     * @param  integer $defaultDaysBack Window used when `from` is unusable.
     * @return array{0:string,1:string} Site-local from Y-m-d, to Y-m-d.
     */
    public static function resolvePickerRange(string $fromRaw, string $toRaw, int $defaultDaysBack = 30): array
    {
        $from = self::normalizeYmd($fromRaw);
        $to   = self::normalizeYmd($toRaw);
        if ($from === '') {
            $from = self::daysAgoYmd($defaultDaysBack);
        }
        if ($to === '') {
            $to = self::todayYmd();
        }

        return self::orderYmd($from, $to);
    }

    /**
     * Inclusive UTC bounds for an optional list/export date filter.
     *
     * Unlike resolvePickerRange(), an absent or unusable bound is simply
     * omitted rather than defaulted — a list with no date filter should
     * return everything, and quietly clamping a bad filter to "last 30
     * days" would hand back a silent subset of an export.
     *
     * @param  string $fromRaw Raw `from` as submitted.
     * @param  string $toRaw   Raw `to` as submitted.
     * @return array{0:string,1:string} UTC start and end; '' for a bound to omit.
     */
    public static function optionalBoundsUtc(string $fromRaw, string $toRaw): array
    {
        $from = self::normalizeYmd($fromRaw);
        $to   = self::normalizeYmd($toRaw);
        if ($from !== '' && $to !== '') {
            list($from, $to) = self::orderYmd($from, $to);
        }

        return [
            $from !== '' ? self::dayStartUtc($from) : '',
            $to !== '' ? self::dayEndUtc($to) : '',
        ];
    }

    /**
     * Put a pair of site-local days in ascending order.
     *
     * @param  string $from Start day, YYYY-MM-DD.
     * @param  string $to   End day, YYYY-MM-DD.
     * @return array{0:string,1:string} The pair, earliest first.
     */
    public static function orderYmd(string $from, string $to): array
    {
        if ($from > $to) {
            return [$to, $from];
        }

        return [$from, $to];
    }

    /**
     * Start of a site-local calendar day, as a UTC MySQL datetime.
     *
     * @param string $ymd YYYY-MM-DD in the site timezone (already normalized).
     */
    public static function dayStartUtc(string $ymd): string
    {
        return (string) get_gmt_from_date($ymd . ' 00:00:00');
    }

    /**
     * End of a site-local calendar day, as a UTC MySQL datetime.
     *
     * @param string $ymd YYYY-MM-DD in the site timezone (already normalized).
     */
    public static function dayEndUtc(string $ymd): string
    {
        return (string) get_gmt_from_date($ymd . ' 23:59:59');
    }

    /**
     * Site UTC offset as a MySQL CONVERT_TZ interval (e.g. "+02:00").
     *
     * Numeric offsets avoid depending on MySQL timezone tables (required for
     * named zones). Uses the offset at $at (default: now), so multi-day ranges
     * that span a DST transition share one offset — same trade-off as other
     * WP plugins that avoid CONVERT_TZ named zones.
     *
     * @param  \DateTimeInterface|null $at Instant whose offset to use, or null for now.
     * @return string Offset formatted as "+HH:MM" / "-HH:MM".
     */
    public static function mysqlUtcOffset(?\DateTimeInterface $at = null): string
    {
        $tz      = wp_timezone();
        $at      = $at ?? new \DateTimeImmutable('now', $tz);
        $seconds = (int) $tz->getOffset($at);
        $sign    = $seconds < 0 ? '-' : '+';
        $seconds = abs($seconds);

        return sprintf('%s%02d:%02d', $sign, intdiv($seconds, 3600), intdiv($seconds % 3600, 60));
    }

    /**
     * SQL expression converting a UTC DATETIME column to the site timezone.
     *
     * Column must be a trusted identifier from SQL_SITE_LOCAL_COLUMNS (never
     * user input). Pair with DATE() / DATE_FORMAT() for day/month/year chart
     * buckets so labels match site-local calendar days from the date picker.
     *
     * Pass $at (typically noon on the range start) so historical ranges use
     * the DST offset that applied then, not "now". A single numeric offset is
     * used for the whole query — ranges that cross a DST transition can
     * mis-bucket clicks near local midnight at the far end of the range by
     * up to one hour (documented trade-off vs per-row named-zone CONVERT_TZ).
     *
     * @param  string                  $column Allowlisted UTC DATETIME column.
     * @param  \DateTimeInterface|null $at     Instant whose DST offset to apply, or null for now.
     * @return string SQL expression yielding the site-local datetime.
     * @throws \InvalidArgumentException When $column is not allowlisted.
     */
    public static function sqlSiteLocal(string $column, ?\DateTimeInterface $at = null): string
    {
        if (!isset(self::SQL_SITE_LOCAL_COLUMNS[$column])) {
            throw new \InvalidArgumentException(
                'SiteDate::sqlSiteLocal() column must be a trusted identifier.'
            );
        }

        $offset = self::mysqlUtcOffset($at);

        return "CONVERT_TZ({$column}, '+00:00', '{$offset}')";
    }

    /**
     * Site-local SQL expression using the DST offset in force on a given day.
     *
     * Takes noon on $ymd so the offset is the one in force for that day
     * rather than "now". An unusable $ymd falls back to the current offset
     * instead of throwing — callers pass a request param, and a bad date
     * should degrade the bucket labels, not 500 the endpoint.
     *
     * @param  string $column Allowlisted UTC DATETIME column.
     * @param  string $ymd    Site-local day the query starts from, YYYY-MM-DD.
     * @return string SQL expression yielding the site-local datetime.
     */
    public static function sqlSiteLocalForDay(string $column, string $ymd): string
    {
        $ymd = self::normalizeYmd($ymd);
        $at  = $ymd !== ''
            ? new \DateTimeImmutable($ymd . ' 12:00:00', wp_timezone())
            : null;

        return self::sqlSiteLocal($column, $at);
    }

    /**
     * Inclusive count of site-local calendar days between two YYYY-MM-DD values.
     *
     * Prefer this over dividing UTC timestamp spans by 86400 — DST fall-back
     * days are 25 hours long and would otherwise inflate the day count used
     * for avg_per_day. Invalid dates return 1 (safe fallback for avg_per_day).
     *
     * @param string $fromYmd YYYY-MM-DD in the site timezone.
     * @param string $toYmd   YYYY-MM-DD in the site timezone.
     */
    public static function calendarDaysInclusive(string $fromYmd, string $toYmd): int
    {
        $fromYmd = self::normalizeYmd($fromYmd);
        $toYmd   = self::normalizeYmd($toYmd);
        if ($fromYmd === '' || $toYmd === '') {
            return 1;
        }

        $tz   = wp_timezone();
        $from = new \DateTimeImmutable($fromYmd . ' 00:00:00', $tz);
        $to   = new \DateTimeImmutable($toYmd . ' 00:00:00', $tz);
        if ($to < $from) {
            return 1;
        }

        return max(1, (int) $from->diff($to)->days + 1);
    }
}

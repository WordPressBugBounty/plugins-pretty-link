<?php

declare(strict_types=1);

namespace PrettyLinks\Redirect;

use PrettyLinks\Support\JobDeadline;

use PrettyLinks\GroundLevel\Resque\Models\Job;

// phpcs:disable PluginCheck.Security.DirectDB.UnescapedDBParameter
// Custom plugin table (prli_clicks): name interpolates from $wpdb->prefix
// (trusted), values bind through $wpdb->prepare().

/**
 * Resolves the geo lookups that the redirect path deliberately skipped, and
 * backfills `prli_clicks.country` for the rows waiting on them.
 *
 * The redirect path never calls the geolocation endpoint for a click row (see
 * ClickWriter::resolveCountry) — it queues the IP instead. This job drains that
 * queue on the Resque worker, where a blocking HTTP call costs nobody any
 * latency, and re-enqueues itself while work remains so one kick drains the
 * whole backlog rather than waiting a cron tick per batch.
 */
class GeoBackfillJob extends Job
{
    /**
     * IPs resolved per run. Each is one potential HTTP request, so keep the
     * batch small enough to finish well inside the worker's time budget; the
     * self re-enqueue picks up whatever is left.
     */
    public const BATCH_SIZE = 20;

    /**
     * Transient guarding how often a drain is queued. Without it, every click
     * with an unresolved IP would enqueue another job — thousands of no-op
     * jobs on a busy site, since the first one drains the whole queue anyway.
     */
    private const DRAIN_LOCK = 'prli_geo_drain_queued';

    /**
     * Queue a drain from the Resque jobs cron when there is work waiting.
     *
     * The redirect path can't be the only trigger. Turbo Mode queues lookups
     * from a dispatcher that exits before the plugin loads, so it has no way to
     * enqueue anything, and a ClickWriter enqueue can fail — either way the
     * pending rows would sit there and click countries would stay empty. Runs
     * on the jobs cron (once a minute) ahead of the worker, so whatever it
     * queues is picked up on the same tick.
     *
     * Two indexed EXISTS queries per tick, and nothing at all on a normal page
     * load.
     *
     * @return void
     */
    public static function maybeQueueDrain(): void
    {
        if (GeoStore::hasPending() || GeoStore::hasExpired()) {
            self::requestDrain();
        }
    }

    /**
     * Queue a drain unless one was queued in the last minute.
     *
     * Called from the redirect path (post-response), so it must stay cheap: a
     * transient read, and at most one job insert per minute.
     *
     * @return void
     */
    public static function requestDrain(): void
    {
        if (get_transient(self::DRAIN_LOCK) !== false) {
            return;
        }

        // Matches the Resque jobs cron interval, so at most one redundant
        // enqueue per tick. Set before enqueueing: if the insert fails we'd
        // rather skip a minute than hammer a failing jobs table.
        set_transient(self::DRAIN_LOCK, 1, MINUTE_IN_SECONDS);

        (new self())->enqueue();
    }

    /**
     * Resolve one batch of queued IPs and backfill their click rows.
     *
     * @return void
     */
    protected function perform(): void
    {
        // Geo's memo is scoped to a request; a worker process handles many, so
        // start from a clean slate rather than inheriting whatever the last job
        // in this process resolved.
        Geo::flushMemo();

        // Expired entries are only filtered on read, so clear them out here —
        // this is the one place that runs regularly and isn't on a request.
        GeoStore::purgeExpired();

        $ips = GeoStore::pending(self::BATCH_SIZE);
        if ($ips === []) {
            return;
        }

        // Stop at the worker's deadline: 20 API calls at up to Geo's 3s
        // timeout would otherwise hold the worker for a minute. Unprocessed
        // IPs stay queued and the chain below resumes.
        $deadline = JobDeadline::at();
        foreach ($ips as $ip) {
            if (microtime(true) >= $deadline) {
                break;
            }
            // Blocking is fine here — this is the background worker, and the
            // lookup populates the shared cache for subsequent redirects.
            $country = Geo::country($ip);

            // Keep the row queued only when we had a country to write and the
            // write itself errored, so the stamp is retried on the next drain.
            // An unresolvable IP is dequeued: it has a negative cache entry now
            // (see Geo::EMPTY_TTL) and leaving it would retry on every drain
            // forever. Zero rows matched is also a success — it just means no
            // click row carries that IP.
            if ($country !== '' && !$this->backfill($ip, $country)) {
                continue;
            }

            GeoStore::dequeue($ip);
        }

        // Chain to the next batch rather than waiting for the next cron tick.
        if (GeoStore::hasPending()) {
            (new self())->enqueue();
        }
    }

    /**
     * Stamp a resolved country onto the click rows still missing one.
     *
     * Matches on the exact IP as stored, so the `ip` index carries the query.
     * Only rows with an empty country are touched, which keeps this safe to
     * re-run and stops it overwriting a country that a CDN header or the
     * `plp_locate_by_ip` filter already supplied.
     *
     * @param  string $ip      The IP whose rows should be stamped.
     * @param  string $country Uppercase ISO 3166-1 alpha-2 code.
     * @return boolean False only when the UPDATE itself errored, so the caller
     *                 can leave the IP queued and retry. Matching zero rows is
     *                 a success.
     */
    private function backfill(string $ip, string $country): bool
    {
        global $wpdb;

        $table = $wpdb->prefix . 'prli_clicks';

        $wpdb->last_error = '';
        // phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Table identifier is the plugin-owned prli_clicks table built from $wpdb->prefix; the country and ip values bind through prepare(). No WP API exists for this table, and an UPDATE has nothing to cache.
        $wpdb->query(
            $wpdb->prepare(
                "UPDATE {$table} SET country = %s WHERE ip = %s AND country = ''",
                $country,
                $ip
            )
        );
        // phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching

        return $wpdb->last_error === '';
    }
}

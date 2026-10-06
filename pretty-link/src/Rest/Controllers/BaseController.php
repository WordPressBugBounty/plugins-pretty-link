<?php

declare(strict_types=1);

namespace PrettyLinks\Rest\Controllers;

use PrettyLinks\GroundLevel\Container\Container;
use PrettyLinks\Repositories\Links;
use PrettyLinks\Rest\Router;
use PrettyLinks\Support\SiteDate;
use WP_REST_Request;

/**
 * Base for every controller in the shared `pretty-links/v1` namespace,
 * including Pro's and the add-ons'. Subclasses are constructed with the
 * container that `prli_loaded` passes and register from `rest_api_init`.
 *
 * @api
 */
abstract class BaseController
{
    /**
     * The service container instance.
     *
     * @var Container
     */
    protected Container $container;

    /**
     * Constructor.
     *
     * @param Container $container The service container instance.
     */
    public function __construct(Container $container)
    {
        $this->container = $container;
    }

    /**
     * Registers the controller's REST routes.
     *
     * @return void
     */
    abstract public function register(): void;

    /**
     * Permission callback alias.
     *
     * @return callable
     */
    protected function permission(): callable
    {
        return [Router::class, 'permissionCheck'];
    }

    /**
     * Returns the REST namespace for this controller.
     *
     * @return string
     */
    protected function namespace(): string
    {
        return Router::NAMESPACE;
    }

    /**
     * Returns the current tracking mode: 'normal', 'extended', or 'count'.
     * Kept on this `@api` base for subclasses; delegates to
     * {@see Links::trackingMode()}.
     */
    protected function trackingMode(): string
    {
        return Links::trackingMode();
    }

    /**
     * True when the site is configured for Simple (count-only) tracking.
     * Kept on this `@api` base for subclasses; delegates to
     * {@see Links::isCountMode()}.
     */
    protected function isCountMode(): bool
    {
        return Links::isCountMode();
    }

    /**
     * Resolve the request's site-local YYYY-MM-DD `from`/`to` into inclusive
     * UTC MySQL datetimes plus the normalized from/to days. Missing or
     * malformed values fall back to last-30-days.
     *
     * @param WP_REST_Request $request The incoming REST request.
     *
     * @return array{0:string,1:string,2:string,3:string} UTC start, UTC end, from Y-m-d, to Y-m-d.
     */
    protected function dateBounds(WP_REST_Request $request): array
    {
        list($from, $to) = SiteDate::resolvePickerRange(
            (string) $request->get_param('from'),
            (string) $request->get_param('to')
        );

        return [SiteDate::dayStartUtc($from), SiteDate::dayEndUtc($to), $from, $to];
    }

    /**
     * REST arg definition for the optional `link_ids` query arg: empty, or
     * comma-separated positive ids (whitespace allowed). Anything else is a 400, so
     * a typo can't silently widen a scoped report to site-wide.
     *
     * @return array<string,mixed>
     */
    protected function linkIdsArg(): array
    {
        return [
            'type'              => 'string',
            'validate_callback' => static function ($value): bool {
                return is_string($value) && preg_match('/^(\s*[1-9]\d*\s*(,\s*[1-9]\d*\s*)*)?$/', $value) === 1;
            },
            'sanitize_callback' => static function ($value): string {
                return (string) preg_replace('/\s+/', '', (string) $value);
            },
        ];
    }

    /**
     * Parse the optional `link_ids` query arg (comma-separated positives).
     * Same shape as the Click History table / export, with no silent cap, so
     * every report consumer and the table stay on the same list of ids.
     *
     * @param WP_REST_Request $request Incoming REST request.
     *
     * @return int[]
     */
    protected function parseLinkIds(WP_REST_Request $request): array
    {
        $raw = (string) $request->get_param('link_ids');
        $ids = [];
        if ($raw !== '') {
            foreach (explode(',', $raw) as $id) {
                $int = (int) trim($id);
                if ($int > 0) {
                    $ids[] = $int;
                }
            }
        }
        return array_values(array_unique($ids));
    }

    /**
     * Bind a trusted column to an optional `IN (...)` of parsed link ids.
     *
     * Pick the column that matches the table in the query's FROM clause:
     * `link_id` for queries on prli_clicks / prli_link_metas, `li.id` for the aliased
     * prli_links join in top-links, and `id` for the unaliased prli_links count.
     *
     * @param string $column  Allowlisted SQL column (never user input).
     * @param int[]  $linkIds Parsed positives; empty means no clause.
     *
     * @return array{0:string,1:int[]} Fragment (leading AND) and params.
     */
    protected function linkIdInSql(string $column, array $linkIds): array
    {
        $allowed = in_array($column, ['link_id', 'id', 'li.id'], true);
        if ($linkIds === [] || ! $allowed) {
            return ['', []];
        }
        $placeholders = implode(',', array_fill(0, count($linkIds), '%d'));
        return [" AND {$column} IN ({$placeholders})", $linkIds];
    }
}

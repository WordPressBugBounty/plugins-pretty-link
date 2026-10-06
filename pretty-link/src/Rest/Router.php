<?php

declare(strict_types=1);

namespace PrettyLinks\Rest;

use PrettyLinks\Admin\Page;
use PrettyLinks\GroundLevel\Container\Container;
use PrettyLinks\Rest\Controllers\AddonsController;
use PrettyLinks\Rest\Controllers\ClicksController;
use PrettyLinks\Rest\Controllers\LicenseController;
use PrettyLinks\Rest\Controllers\LinksController;
use PrettyLinks\Rest\Controllers\NoticesController;
use PrettyLinks\Rest\Controllers\OptionsController;
use PrettyLinks\Rest\Controllers\ReviewNoticeController;
use PrettyLinks\Rest\Controllers\PreferencesController;
use PrettyLinks\Rest\Controllers\ReportsController;
use PrettyLinks\Rest\Controllers\StripeController;
use PrettyLinks\Rest\Controllers\ToolsController;
use WP_REST_Request;

/**
 * Internal admin REST surface at `/wp-json/pretty-links/v1`. Nonce-gated,
 * capability-checked. NOT the public Developer Tools API.
 *
 * Add-ons register their admin routes here too, under their own path prefix
 * (`/<addon>/...`), by extending `Controllers\BaseController`. That gets them
 * the shared permission check, `CacheControl`'s cache opt-outs and the
 * cache-busting `api` client in `@pl/shared` for free. Everything in this
 * namespace is admin-only, except Pro's anonymous `POST /pro/public-links`;
 * front-end add-on features use admin-ajax instead.
 *
 * @api
 */
class Router
{
    /**
     * The shared admin REST namespace.
     *
     * @api
     */
    public const NAMESPACE = 'pretty-links/v1';

    /**
     * The service container instance.
     *
     * @var Container
     */
    private Container $container;

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
     * Registers all REST controllers for the admin surface.
     *
     * @return void
     */
    public function register(): void
    {
        (new LinksController($this->container))->register();
        (new ClicksController($this->container))->register();
        (new OptionsController($this->container))->register();
        (new ReportsController($this->container))->register();
        (new NoticesController($this->container))->register();
        (new ReviewNoticeController($this->container))->register();
        (new ToolsController($this->container))->register();
        (new LicenseController($this->container))->register();
        (new StripeController($this->container))->register();
        (new AddonsController($this->container))->register();
        (new PreferencesController($this->container))->register();
    }

    /**
     * Standard permission callback: capability check.
     *
     * Cookie-authenticated browser requests are covered by WordPress's own
     * REST nonce enforcement (wp-api-fetch sends X-WP-Nonce automatically).
     * Application-password / OAuth requests pass their own credentials and
     * don't need a nonce — checking the configured admin capability is
     * sufficient there.
     *
     * @api
     *
     * @param WP_REST_Request $request The incoming REST request.
     *
     * @return boolean
     */
    public static function permissionCheck(WP_REST_Request $request): bool
    {
        unset($request);
        return current_user_can(Page::capability());
    }
}

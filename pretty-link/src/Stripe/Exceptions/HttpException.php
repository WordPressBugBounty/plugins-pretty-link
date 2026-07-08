<?php

declare(strict_types=1);

namespace PrettyLinks\Stripe\Exceptions;

use RuntimeException;

/**
 * Transport-level failure talking to api.stripe.com (WP_Error from
 * wp_remote_request, timeout, etc.). Analogous to v3 PrliHttpException.
 */
class HttpException extends RuntimeException
{
}

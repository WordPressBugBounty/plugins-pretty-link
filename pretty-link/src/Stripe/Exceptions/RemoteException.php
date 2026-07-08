<?php

declare(strict_types=1);

namespace PrettyLinks\Stripe\Exceptions;

use RuntimeException;

/**
 * Stripe responded with a JSON error body (e.g. invalid_request_error).
 * Analogous to v3 PrliRemoteException.
 */
class RemoteException extends RuntimeException
{
}

<?php

declare(strict_types=1);

namespace PrettyLinks\GroundLevel\Resque\Concerns;

use PrettyLinks\GroundLevel\Support\Str;

/**
 * Trait containing prefixes related utils.
 *
 * Classes using this trait must define a `$prefix` property.
 */
trait NormalizedPrefix
{
    /**
     * Returns the normalized Service prefix (without trailing underscore).
     *
     * @return string
     */
    private function getNormalizedPrefix(): string
    {
        return Str::untrailingUnderscoreIt($this->prefix);
    }
}

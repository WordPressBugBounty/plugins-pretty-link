<?php

declare(strict_types=1);

namespace PrettyLinks\Shortcodes;

/**
 * Register all Pretty Links shortcodes in one place.
 */
class Loader
{
    /**
     * Registers all Pretty Links shortcodes.
     *
     * @return void
     */
    public static function register(): void
    {
        add_shortcode(PrettyLink::TAG, [PrettyLink::class, 'render']);
        add_shortcode(PostPrettyLink::TAG, [PostPrettyLink::class, 'render']);
    }
}

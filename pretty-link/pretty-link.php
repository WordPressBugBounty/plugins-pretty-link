<?php

/*
Plugin Name: Pretty Links
Plugin URI: https://prettylinks.com/pl/plugin-uri
Description: Shrink, track and share any URL using your website and brand.
Author: Pretty Links
Author URI: https://prettylinks.com
Version: 4.0.4
Requires at least: 6.0
Requires PHP: 7.4
License: GPLv2 or later
Text Domain: pretty-link
Domain Path: /languages
*/

declare(strict_types=1);

defined('ABSPATH') || exit;

/*
 * The vendor-prefixed autoloader is used to autoload classes from the Ground Level
 * framework.
 *
 * These classes are renamespaced during a build step in order to prevent
 * conflicts with other plugins which may also utilize the Ground Level framework.
 */
require_once 'vendor-prefixed/autoload.php';

if (!defined('PRLI_FILE')) {
    define('PRLI_FILE', __FILE__);
}
if (!defined('PRLI_PATH')) {
    define('PRLI_PATH', plugin_dir_path(PRLI_FILE));
}
if (!defined('PRLI_URL')) {
    define('PRLI_URL', plugin_dir_url(PRLI_FILE));
}
// The build script swaps the slug per-edition ZIP (Pro-Blogger, Pro-Developer,
// etc). Lite ships with `pretty-link-lite`. `EditionMismatch` reads this to
// detect when the installed edition doesn't match the active license.
if (!defined('PRLI_EDITION')) {
    define('PRLI_EDITION', 'pretty-link-lite');
}

require_once 'src/functions.php';
// Deprecated v3 API function shims — thin wrappers around the v4 repo.
// Kept only so plugins/themes/snippets using the v3 API keep working.
require_once 'src/legacy-shims.php';
// MonsterInsights cross-plugin compat: redirects MI's URL Builder flow
// from v3's CPT URL to v4's add-link page and stubs the small surface
// MI calls inside its prli_before_redirect handler.
require_once 'src/monsterinsights-shim.php';
use function PrettyLinks\prettyLinksApp;

return prettyLinksApp(__FILE__);

<?php
/**
 * Plugin Name: Open WP Cache
 * Plugin URI: https://wpgod.com/wordpress-plugins/open-wp-cache/
 * Description: A small, free page cache for public, anonymous WordPress HTML.
 * Version: 0.1.0
 * Requires at least: 6.9
 * Requires PHP: 8.3
 * Author: Mike Moisio
 * Author URI: https://wpgod.com
 * License: GPL-2.0-or-later
 * License URI: https://www.gnu.org/licenses/old-licenses/gpl-2.0.html
 * Text Domain: open-wp-cache
 *
 * @package OpenWPCache
 */

namespace OpenWPCache;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'OPEN_WP_CACHE_VERSION', '0.1.0' );

require_once __DIR__ . '/includes/class-settings.php';
require_once __DIR__ . '/includes/class-cache-key.php';
require_once __DIR__ . '/includes/class-storage.php';
require_once __DIR__ . '/includes/class-invalidation.php';
require_once __DIR__ . '/includes/class-eligibility.php';
require_once __DIR__ . '/includes/class-http.php';
require_once __DIR__ . '/includes/class-statistics.php';
require_once __DIR__ . '/includes/class-diagnostics.php';
require_once __DIR__ . '/includes/class-page-cache.php';
require_once __DIR__ . '/includes/class-bootstrap.php';

register_activation_hook( __FILE__, array( Bootstrap::class, 'activate' ) );
register_deactivation_hook( __FILE__, array( Bootstrap::class, 'deactivate' ) );
add_action( 'admin_notices', array( Bootstrap::class, 'notice' ) );
add_action( 'open_wp_cache_cleanup', array( Bootstrap::class, 'cleanup' ) );
add_action( 'template_redirect', array( Page_Cache::class, 'handle' ), PHP_INT_MAX );
Invalidation::register();
Statistics::register();
if ( is_admin() ) {
	require_once __DIR__ . '/includes/class-admin.php';
	Admin::register();
}
if ( defined( 'WP_CLI' ) && WP_CLI ) {
	require_once __DIR__ . '/includes/class-cli.php';
	\WP_CLI::add_command( 'open-wp-cache', CLI::class );
}

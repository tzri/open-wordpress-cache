<?php
/**
 * Remove owned configuration only in WordPress's uninstall context.
 *
 * @package OpenWPCache
 */

if ( ! defined( 'ABSPATH' ) || ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

foreach ( array( 'settings', 'cache-key', 'storage', 'invalidation', 'statistics', 'lifecycle' ) as $module ) {
	require_once __DIR__ . '/includes/class-' . $module . '.php';
}
$open_wp_cache_result = \OpenWPCache\Lifecycle::uninstall();
if ( ! $open_wp_cache_result['success'] ) {
	$open_wp_cache_message = __( 'Some Open WP Cache data remain because storage or the database was unavailable, unsafe, busy or exceeded the cleanup budget. Repair database health and ownership/permissions, then retry per-site uninstall before deleting plugin files. Foreign files are preserved.', 'open-wp-cache' );
	if ( defined( 'WP_CLI' ) && WP_CLI ) {
		\WP_CLI::error( $open_wp_cache_message );
	}
	wp_die(
		esc_html( $open_wp_cache_message ),
		'',
		array(
			'response'  => 500,
			'back_link' => true,
		)
	);
}

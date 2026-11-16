<?php
/**
 * Guarded uninstall for the selected site; never walk a whole multisite network.
 *
 * @package OpenWPCache
 */

namespace OpenWPCache;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Destructive entry point used only by WordPress uninstall context. */
final class Lifecycle {
	/**
	 * Remove configuration first, preventing mutation hooks from recreating storage.
	 * Filesystem refusals preserve foreign data and are reported to CLI operators.
	 *
	 * @return array Completion flags; unsafe/unwritable/budget-limited files remain for retry.
	 */
	public static function uninstall(): array {
		if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) || 'open-wp-cache/open-wp-cache.php' !== WP_UNINSTALL_PLUGIN ) {
			return array(
				'success' => false,
				'reason'  => 'uninstall_context',
			);
		}
		wp_clear_scheduled_hook( 'open_wp_cache_cleanup' );
		delete_option( Settings::OPTION );
		if ( false !== get_option( Settings::OPTION, false ) ) {
			return array(
				'success' => false,
				'reason'  => 'database_unavailable',
			);
		}
		$cache = false;
		try {
			$uploads = wp_upload_dir( null, false );
			$base    = $uploads['basedir'] ?? '';
			if ( is_string( $base ) && '' !== $base && ! wp_is_stream( $base ) ) {
				$path  = $base . '/open-wp-cache/site-' . Cache_Key::site( get_current_blog_id(), ABSPATH );
				$cache = ! file_exists( $path ) && ! is_link( $path ) ? true : Storage::from_wordpress()->remove();
			}
		} catch ( \Throwable $error ) {
			$cache = false;
		}
		try {
			$statistics = Statistics::from_wordpress()->remove();
		} catch ( \Throwable $error ) {
			$statistics = false;
		}
		$options_removed = true;
		foreach ( array( 'open_wp_cache_setup_error', Invalidation::LAST_OPTION, Invalidation::ERROR_OPTION ) as $option ) {
			delete_option( $option );
			$options_removed = $options_removed && false === get_option( $option, false );
		}
		global $wpdb;
		// On-demand uninstall only, bounded and API-deleted for object-cache consistency.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- One bounded uninstall prefix lookup; deletions use WordPress APIs.
		$names = $wpdb->get_col( $wpdb->prepare( "SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s LIMIT 1000", $wpdb->esc_like( '_transient_open_wp_cache_notice_' ) . '%', $wpdb->esc_like( '_transient_timeout_open_wp_cache_notice_' ) . '%' ) );
		foreach ( $names as $name ) {
			if ( preg_match( '/\A_transient_(?:timeout_)?open_wp_cache_notice_([1-9][0-9]*)\z/D', $name, $match ) ) {
				delete_transient( 'open_wp_cache_notice_' . $match[1] );
			}
		}
		return array(
			'success'            => $cache && $statistics && $options_removed && count( $names ) < 1000,
			'cache_removed'      => $cache,
			'statistics_removed' => $statistics,
		);
	}
}

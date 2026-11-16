<?php
/**
 * Plugin lifecycle and actionable setup diagnostics.
 *
 * @package OpenWPCache
 */

namespace OpenWPCache;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** WordPress lifecycle entry points. */
final class Bootstrap {

	/**
	 * Initialize this site's settings, preserving an existing configuration.
	 *
	 * @param bool $network_wide Whether network activation was requested.
	 */
	public static function activate( bool $network_wide = false ): void {
		if ( $network_wide ) {
			wp_die( esc_html__( 'Open WP Cache supports per-site activation. Activate it separately on each site; network activation is not supported.', 'open-wp-cache' ) );
		}
		add_option( Settings::OPTION, Settings::defaults(), '', false );
		$error = self::storage_error();
		if ( $error ) {
			$settings            = Settings::get();
			$settings['enabled'] = false;
			update_option( Settings::OPTION, $settings, false );
			update_option( 'open_wp_cache_setup_error', $error, false );
		} else {
			delete_option( 'open_wp_cache_setup_error' );
			Invalidation::clear( 'settings' );
			if ( ! wp_next_scheduled( 'open_wp_cache_cleanup' ) ) {
				wp_schedule_event( time() + HOUR_IN_SECONDS, 'hourly', 'open_wp_cache_cleanup' );
			}
		}
	}

	/** Deactivation stops cleanup and invalidates owned entries, preserving settings. */
	public static function deactivate(): void {
		if ( Invalidation::clear( 'deactivation' )['success'] ) {
			Storage::from_wordpress()->cleanup();
		}
		wp_clear_scheduled_hook( 'open_wp_cache_cleanup' );
	}

	/** Perform one bounded cleanup sweep, never scanning directories on cache hits. */
	public static function cleanup(): void {
		Storage::from_wordpress()->cleanup();
		try {
			Statistics::from_wordpress()->cleanup();
		} catch ( \Throwable $error ) {
			return;
		}
	}

	/**
	 * Return a fixed reason code, never a private filesystem path.
	 *
	 * @return string
	 */
	public static function storage_error(): string {
		if ( defined( 'WP_CACHE' ) && WP_CACHE && file_exists( WP_CONTENT_DIR . '/advanced-cache.php' ) ) {
			return 'existing_page_cache';
		}
		$uploads = wp_upload_dir();
		$basedir = $uploads['basedir'] ?? '';
		if ( ! empty( $uploads['error'] ) || ! is_string( $basedir ) || '' === $basedir || wp_is_stream( $basedir ) || ! is_dir( $basedir ) || ! wp_is_writable( $basedir ) ) {
			return 'uploads_unavailable';
		}
		$storage = Storage::from_wordpress();
		return $storage->operational() ? '' : $storage->error();
	}

	/** Render an admin-only setup notice without exposing upload paths. */
	public static function notice(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$error = get_option( 'open_wp_cache_setup_error', '' );
		if ( get_option( Invalidation::ERROR_OPTION, '' ) ) {
			$message = __( 'Open WP Cache is disabled after a failed cache clear. Restore local uploads permissions and clear the cache successfully before using cached pages again.', 'open-wp-cache' );
			echo '<div class="notice notice-error"><p>' . esc_html( $message ) . '</p></div>';
			return;
		}
		if ( ! $error ) {
			return;
		}
		$message = 'existing_page_cache' === $error
			? __( 'Open WP Cache is disabled because an existing page-cache drop-in is active. Resolve the conflict before enabling this cache.', 'open-wp-cache' )
			: __( 'Open WP Cache is disabled because local uploads storage is unavailable or unwritable. Normal WordPress rendering continues. Check uploads permissions before enabling the cache.', 'open-wp-cache' );
		echo '<div class="notice notice-warning"><p>' . esc_html( $message ) . '</p></div>';
	}
}

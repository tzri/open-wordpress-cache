<?php
/**
 * On-demand status shared by administration and WP-CLI.
 *
 * @package OpenWPCache
 */

namespace OpenWPCache;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Entry points enforce capability/nonce requirements; this service returns safe data. */
final class Diagnostics {
	/**
	 * Read bounded physical usage and private observations only on demand.
	 *
	 * @param int $budget Cache directory-entry inspection cap.
	 * @return array
	 */
	public static function report( int $budget = 1000 ): array {
		$settings = Settings::get();
		$error    = Bootstrap::storage_error();
		$storage  = '' === $error ? Storage::from_wordpress() : null;
		$reason   = '' !== $error ? $error : ( get_option( Invalidation::ERROR_OPTION, '' ) ? 'invalidation_failed' : ( $settings['enabled'] ? '' : 'disabled' ) );
		$last     = get_option( Invalidation::LAST_OPTION, null );
		if ( ! is_array( $last ) || ! is_string( $last['utc'] ?? null ) || ! preg_match( '/\A[0-9]{4}-[0-9]{2}-[0-9]{2}T[0-9]{2}:[0-9]{2}:[0-9]{2}Z\z/D', $last['utc'] ) || ! in_array( $last['reason'] ?? null, array( 'manual', 'cli', 'settings', 'post', 'comment', 'taxonomy', 'appearance', 'option', 'deactivation' ), true ) ) {
			$last = null;
		} else {
			$last = array(
				'utc'    => $last['utc'],
				'reason' => $last['reason'],
			);
		}
		try {
			$statistics = Statistics::from_wordpress()->snapshot();
		} catch ( \Throwable $error ) {
			$statistics = array(
				'available' => false,
				'reason'    => 'metrics_unavailable',
			);
		}
		return array(
			'version'               => OPEN_WP_CACHE_VERSION,
			'enabled'               => $settings['enabled'],
			'operational'           => '' === $reason,
			'state'                 => '' === $reason ? 'READY' : 'DISABLED',
			'reason'                => $reason,
			'generation'            => null !== $storage ? $storage->generation() : null,
			'size'                  => null !== $storage ? $storage->usage( $budget ) : array(
				'available' => false,
				'partial'   => true,
			),
			'last_clear'            => $last,
			'statistics'            => $statistics,
			'max_entry_bytes'       => Storage::MAX_ENTRY,
			'body_quota_bytes'      => Storage::MAX_SITE,
			'native_gzip_available' => function_exists( 'gzencode' ),
			'static_headers_owned'  => false,
		);
	}
}

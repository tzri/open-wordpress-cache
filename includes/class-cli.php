<?php
/**
 * Optional WP-CLI entry points; never loaded on ordinary web requests.
 *
 * @package OpenWPCache
 */

namespace OpenWPCache;

if ( ! defined( 'ABSPATH' ) || ! defined( 'WP_CLI' ) || ! WP_CLI ) {
	exit;
}

/** Cache administration for the site selected by WP-CLI's --url. */
final class CLI {
	/**
	 * Clear this site's server cache, preserving settings and estimates.
	 */
	public function clear(): void {
		$result = Invalidation::clear( 'cli' );
		if ( ! $result['success'] ) {
			\WP_CLI::error( __( 'Cache clear failed. Check local uploads permissions and ownership, then retry; caching remains disabled.', 'open-wp-cache' ) );
		}
		if ( ! $result['metadata_saved'] ) {
			\WP_CLI::error( __( 'Cache cleared, but the last-clear record could not be saved. Check database health.', 'open-wp-cache' ) );
		}
		/* translators: %s: UTC clear time. */
		\WP_CLI::success( sprintf( __( 'Cache cleared at %s. Obsolete files await bounded cleanup; fresh browser copies retain their TTL.', 'open-wp-cache' ), $result['cleared_at'] ) );
	}

	/**
	 * Report this site's effective health, bounded sizes and sampled estimates.
	 *
	 * ## OPTIONS
	 *
	 * [--format=<format>]
	 * : Output format.
	 * ---
	 * default: text
	 * options:
	 *   - text
	 *   - json
	 * ---
	 *
	 * @param array $args Positional arguments.
	 * @param array $assoc_args Named arguments.
	 */
	public function status( array $args, array $assoc_args ): void {
		$format = $assoc_args['format'] ?? 'text';
		if ( ! in_array( $format, array( 'text', 'json' ), true ) ) {
			\WP_CLI::error( __( 'Use --format=text or --format=json.', 'open-wp-cache' ) );
		}
		$report = Diagnostics::report();
		if ( 'json' === $format ) {
			\WP_CLI::line( wp_json_encode( $report, JSON_UNESCAPED_SLASHES ) );
		} else {
			/* translators: 1: READY/DISABLED, 2: fixed reason code. */
			\WP_CLI::line( sprintf( __( 'State: %1$s; reason: %2$s', 'open-wp-cache' ), $report['state'], '' === $report['reason'] ? 'ready' : $report['reason'] ) );
			$size = $report['size'];
			if ( $size['available'] ) {
				/* translators: 1: bytes, 2: HTML count, 3: partial marker. */
				\WP_CLI::line( sprintf( __( 'Active cache: %1$d bytes; %2$d HTML files%3$s', 'open-wp-cache' ), $size['active_bytes'], $size['active_body_files'], $size['partial'] ? __( ' (partial lower bounds)', 'open-wp-cache' ) : '' ) );
				/* translators: %d: obsolete logical file bytes. */
				\WP_CLI::line( sprintf( __( 'Obsolete bytes awaiting cleanup: %d', 'open-wp-cache' ), $size['obsolete_bytes'] ) );
			} else {
				\WP_CLI::line( __( 'Cache size/count: unavailable (unknown). Check local uploads permissions and ownership.', 'open-wp-cache' ) );
			}
			/* translators: %s: UTC clear time, or unknown. */
			\WP_CLI::line( sprintf( __( 'Last clear (UTC): %s', 'open-wp-cache' ), $report['last_clear']['utc'] ?? __( 'unknown', 'open-wp-cache' ) ) );
			$metrics = $report['statistics'];
			if ( $metrics['available'] ) {
				/* translators: 1: hits, 2: misses, 3: sample denominator, 4: accepted samples. */
				\WP_CLI::line( sprintf( __( 'Estimated hits: %1$d; misses: %2$d; current sampling: 1/%3$d; accepted samples: %4$d. Busy samples may be dropped.', 'open-wp-cache' ), $metrics['data']['counts']['hit'], $metrics['data']['counts']['miss'], $metrics['current_period'], $metrics['data']['samples'] ) );
			} else {
				\WP_CLI::line( __( 'Traffic estimates unavailable; check private local temp storage.', 'open-wp-cache' ) );
			}
		}
		if ( ! $report['operational'] ) {
			\WP_CLI::halt( 1 );
		}
	}
}

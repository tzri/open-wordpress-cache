<?php
/**
 * One native, site-local Settings API screen.
 *
 * @package OpenWPCache
 */

namespace OpenWPCache;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Authorized administration entry points; frontend requests never load this class. */
final class Admin {
	const GROUP = 'open_wp_cache';
	const PAGE  = 'open-wp-cache';

	/** Attach native admin hooks. */
	public static function register(): void {
		add_action( 'admin_menu', array( self::class, 'menu' ) );
		add_action( 'admin_init', array( self::class, 'settings' ) );
		add_action( 'admin_post_open_wp_cache_clear', array( self::class, 'clear' ) );
	}

	/** Add exactly one screen under this site's Settings. */
	public static function menu(): void {
		add_options_page( __( 'Open WordPress Cache', 'open-wp-cache' ), __( 'Open WordPress Cache', 'open-wp-cache' ), 'manage_options', self::PAGE, array( self::class, 'render' ) );
	}

	/** Register fields and the strict all-or-nothing settings sanitizer. */
	public static function settings(): void {
		// Core accepts action/option_page from REQUEST. Our settings updates require POST.
		if ( self::GROUP === filter_input( INPUT_GET, 'option_page' ) && 'POST' !== ( isset( $_SERVER['REQUEST_METHOD'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REQUEST_METHOD'] ) ) : '' ) ) {
			wp_die( esc_html__( 'Saving cache settings requires a POST request.', 'open-wp-cache' ), '', array( 'response' => 405 ) );
		}
		register_setting(
			self::GROUP,
			Settings::OPTION,
			array(
				'type'              => 'array',
				'sanitize_callback' => array( self::class, 'sanitize' ),
				'show_in_rest'      => false,
			)
		);
		add_settings_section( 'cache', __( 'Page cache', 'open-wp-cache' ), '__return_false', self::PAGE );
		add_settings_section( 'browser', __( 'Advanced browser behavior', 'open-wp-cache' ), '__return_false', self::PAGE );
		foreach ( array(
			'enabled'     => __( 'Enable page caching', 'open-wp-cache' ),
			'disk_ttl'    => __( 'Disk expiration (seconds)', 'open-wp-cache' ),
			'exclusions'  => __( 'URL path exclusions', 'open-wp-cache' ),
			'gzip'        => __( 'Enable gzip for cached HTML', 'open-wp-cache' ),
			'browser_ttl' => __( 'HTML browser TTL (seconds)', 'open-wp-cache' ),
		) as $key => $label ) {
			add_settings_field(
				$key,
				$label,
				array( self::class, 'field' ),
				self::PAGE,
				'browser_ttl' === $key ? 'browser' : 'cache',
				array(
					'label_for' => 'owc-' . $key,
					'key'       => $key,
				)
			);
		}
	}

	/**
	 * Reject malformed submissions together, retaining the entire previous value.
	 * Settings API options.php supplies the capability, POST and session nonce gate.
	 *
	 * @param mixed $input Unslashed submitted settings.
	 * @return array
	 */
	public static function sanitize( $input ): array {
		$old   = get_option( Settings::OPTION, Settings::defaults() );
		$old   = is_array( $old ) ? $old : Settings::get();
		$valid = is_array( $input ) && ! array_diff_key( Settings::defaults(), $input ) && ! array_diff_key( $input, Settings::defaults() );
		if ( $valid ) {
			foreach ( array( 'enabled', 'gzip' ) as $key ) {
				$valid = $valid && in_array( $input[ $key ], array( '0', '1', 0, 1, false, true ), true );
			}
			foreach ( array(
				'disk_ttl'    => array( Settings::MIN_DISK_TTL, Settings::MAX_DISK_TTL ),
				'browser_ttl' => array( 0, Settings::MAX_BROWSER_TTL ),
			) as $key => $bounds ) {
				$number = $input[ $key ];
				$valid  = $valid && ( is_int( $number ) || is_string( $number ) ) && preg_match( '/\A[0-9]{1,6}\z/D', (string) $number ) && (int) $number >= $bounds[0] && (int) $number <= $bounds[1];
			}
			$valid = $valid && is_string( $input['exclusions'] ) && null !== Settings::exclusion_lines( $input['exclusions'] );
		}
		if ( ! $valid ) {
			add_settings_error( self::GROUP, 'invalid_settings', __( 'Settings were not changed. Use whole-number TTLs within the displayed limits, valid checkboxes, and at most 50 literal exclusion lines (256 bytes each, 8192 bytes total). Invalid percent escapes and control characters are rejected.', 'open-wp-cache' ) );
			return $old;
		}
		// Keep encoded text encoded: decoding here would decode it twice during matching.
		$input['exclusions'] = implode( "\n", array_filter( array_map( 'trim', preg_split( '/\r\n|\r|\n/', $input['exclusions'] ) ), static fn( string $line ): bool => '' !== $line ) );
		$settings            = Settings::normalize( $input );
		if ( $settings['enabled'] && '' === Bootstrap::storage_error() ) {
			delete_option( 'open_wp_cache_setup_error' );
		}
		return $settings;
	}

	/**
	 * Render one labeled native control.
	 *
	 * @param array $args Settings field arguments.
	 */
	public static function field( array $args ): void {
		$key          = $args['key'];
		$value        = Settings::get()[ $key ];
		$id           = 'owc-' . $key;
		$name         = Settings::OPTION . '[' . $key . ']';
		$descriptions = array(
			'enabled'     => __( 'Cache only public, anonymous HTML. Private pages, cookies and query strings bypass the cache. HTML files in uploads are publicly readable: exclude sensitive content before enabling.', 'open-wp-cache' ),
			'disk_ttl'    => __( '60–604800 seconds; default 3600 (one hour). Content changes invalidate server entries immediately.', 'open-wp-cache' ),
			'exclusions'  => __( 'One literal, case-sensitive path substring per line, for example /members/. Not regular expressions. Paths and exclusions are percent-decoded once. Up to 50 lines, 256 bytes per line and 8192 bytes total.', 'open-wp-cache' ),
			'gzip'        => __( 'Off by default. Uses native PHP gzip when available and accepted by the browser; existing compression bypasses the cache. Hosting controls static image, CSS and JavaScript headers.', 'open-wp-cache' ),
			'browser_ttl' => __( '0–3600 seconds; default 0 requires revalidation. A positive value allows a browser to reuse old HTML until it expires, even after Clear Cache. This does not change disk expiration or static asset headers.', 'open-wp-cache' ),
		);
		if ( in_array( $key, array( 'enabled', 'gzip' ), true ) ) {
			?>
			<input type="hidden" name="<?php echo esc_attr( $name ); ?>" value="0">
			<input type="checkbox" id="<?php echo esc_attr( $id ); ?>" name="<?php echo esc_attr( $name ); ?>" value="1" aria-describedby="<?php echo esc_attr( $id . '-help' ); ?>" <?php checked( $value ); ?>>
			<?php
		} elseif ( 'exclusions' === $key ) {
			?>
			<textarea class="large-text code" rows="5" id="<?php echo esc_attr( $id ); ?>" name="<?php echo esc_attr( $name ); ?>" maxlength="8192" aria-describedby="<?php echo esc_attr( $id . '-help' ); ?>"><?php echo esc_textarea( $value ); ?></textarea>
			<?php
		} else {
			?>
			<input type="number" class="small-text" required step="1" min="<?php echo esc_attr( 'disk_ttl' === $key ? Settings::MIN_DISK_TTL : 0 ); ?>" max="<?php echo esc_attr( 'disk_ttl' === $key ? Settings::MAX_DISK_TTL : Settings::MAX_BROWSER_TTL ); ?>" id="<?php echo esc_attr( $id ); ?>" name="<?php echo esc_attr( $name ); ?>" value="<?php echo esc_attr( $value ); ?>" aria-describedby="<?php echo esc_attr( $id . '-help' ); ?>">
			<?php
		}
		echo '<p class="description" id="' . esc_attr( $id . '-help' ) . '">' . esc_html( $descriptions[ $key ] ) . '</p>';
	}

	/** POST-only manual clear, followed by a fixed local redirect and one-time feedback. */
	public static function clear(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You cannot manage this cache.', 'open-wp-cache' ), '', array( 'response' => 403 ) );
		}
		if ( 'POST' !== ( isset( $_SERVER['REQUEST_METHOD'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REQUEST_METHOD'] ) ) : '' ) ) {
			wp_die( esc_html__( 'Clear Cache requires a POST request.', 'open-wp-cache' ), '', array( 'response' => 405 ) );
		}
		check_admin_referer( 'open_wp_cache_clear', 'open_wp_cache_clear_nonce' );
		$result = Invalidation::clear( 'manual' );
		$code   = ! $result['success'] ? 'failed' : ( $result['metadata_saved'] ? 'cleared' : 'metadata_failed' );
		set_transient( 'open_wp_cache_notice_' . get_current_user_id(), $code, MINUTE_IN_SECONDS );
		wp_safe_redirect( admin_url( 'options-general.php?page=' . self::PAGE ) );
		exit;
	}

	/**
	 * Safe messages for fixed health codes; never expose paths or raw diagnostics.
	 *
	 * @param string $reason Fixed health enum.
	 */
	private static function health( string $reason ): string {
		if ( '' === $reason ) {
			return __( 'Ready to cache eligible public pages.', 'open-wp-cache' );
		}
		if ( 'disabled' === $reason ) {
			return __( 'Page caching is switched off. Enable it below when ready.', 'open-wp-cache' );
		}
		if ( 'existing_page_cache' === $reason ) {
			return __( 'An active page-cache drop-in conflicts with this plugin. Resolve it with your host before enabling caching.', 'open-wp-cache' );
		}
		if ( 'invalidation_failed' === $reason ) {
			return __( 'A previous clear failed. Restore local uploads permissions, then use Clear Cache to recover safely.', 'open-wp-cache' );
		}
		return __( 'Local uploads storage is unavailable, unsafe or unwritable. Check local uploads permissions and cache ownership with your host, then use Clear Cache. Normal WordPress rendering continues.', 'open-wp-cache' );
	}

	/** Render accessible one-time action feedback. */
	private static function feedback(): void {
		$key  = 'open_wp_cache_notice_' . get_current_user_id();
		$code = get_transient( $key );
		delete_transient( $key );
		$messages = array(
			'cleared'         => __( 'Cache cleared. New requests use a fresh server generation; physical cleanup runs hourly. Already-fresh browser copies expire according to their browser TTL.', 'open-wp-cache' ),
			'metadata_failed' => __( 'Cache cleared, but the last-clear record could not be saved. Check database health.', 'open-wp-cache' ),
			'failed'          => __( 'Cache could not be cleared. Caching is disabled to prevent stale responses. Check local uploads permissions and ownership, then retry Clear Cache.', 'open-wp-cache' ),
		);
		if ( is_string( $code ) && isset( $messages[ $code ] ) ) {
			echo '<div class="notice ' . esc_attr( 'cleared' === $code ? 'notice-success' : 'notice-error' ) . '" role="' . esc_attr( 'cleared' === $code ? 'status' : 'alert' ) . '"><p>' . esc_html( $messages[ $code ] ) . '</p></div>';
		}
	}

	/** Render only for authorized site administrators. */
	public static function render(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You cannot manage this cache.', 'open-wp-cache' ), '', array( 'response' => 403 ) );
		}
		$report  = Diagnostics::report();
		$size    = $report['size'];
		$metrics = $report['statistics'];
		?>
		<div class="wrap" id="open-wp-cache">
			<h1><?php esc_html_e( 'Open WordPress Cache', 'open-wp-cache' ); ?></h1>
			<?php self::feedback(); ?>
			<p><?php esc_html_e( 'PHP page caching for this site. WordPress still starts on cache hits. Activate and configure each multisite site separately; network activation is unavailable.', 'open-wp-cache' ); ?></p>
			<h2><?php esc_html_e( 'Status', 'open-wp-cache' ); ?></h2>
			<p><strong><?php echo esc_html( $report['operational'] ? __( 'Ready', 'open-wp-cache' ) : __( 'Disabled', 'open-wp-cache' ) ); ?></strong> — <?php echo esc_html( self::health( $report['reason'] ) ); ?></p>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="open_wp_cache_clear">
				<?php wp_nonce_field( 'open_wp_cache_clear', 'open_wp_cache_clear_nonce', false ); ?>
				<?php submit_button( __( 'Clear Cache', 'open-wp-cache' ), 'primary', 'owc-clear' ); ?>
			</form>
			<table class="widefat striped" aria-label="<?php esc_attr_e( 'Cache diagnostics', 'open-wp-cache' ); ?>"><tbody>
				<tr><th scope="row"><?php esc_html_e( 'Active cache', 'open-wp-cache' ); ?></th><td>
				<?php if ( $size['available'] ) : ?>
					<?php
					/* translators: 1: formatted byte size, 2: HTML file count. */
					echo esc_html( sprintf( __( '%1$s; %2$s HTML files', 'open-wp-cache' ), size_format( $size['active_bytes'] ), number_format_i18n( $size['active_body_files'] ) ) );
					?>
					<p class="description"><?php echo esc_html( $size['partial'] ? __( 'Partial scan: displayed sizes and counts are lower bounds.', 'open-wp-cache' ) : __( 'Logical file sizes include metadata; HTML counts include expired candidates awaiting cleanup.', 'open-wp-cache' ) ); ?></p>
				<?php else : ?>
					<?php esc_html_e( 'Unavailable. Repair storage permissions or ownership to measure usage.', 'open-wp-cache' ); ?>
				<?php endif; ?>
				</td></tr>
				<tr><th scope="row"><?php esc_html_e( 'Obsolete generations', 'open-wp-cache' ); ?></th><td><?php echo esc_html( $size['available'] ? size_format( $size['obsolete_bytes'] ) . ( $size['partial'] ? ' +' : '' ) : __( 'Unknown', 'open-wp-cache' ) ); ?> <span class="description"><?php esc_html_e( 'Pending bounded hourly cleanup; active HTML body quota is 50 MiB and each page is limited to 2 MiB.', 'open-wp-cache' ); ?></span></td></tr>
				<tr><th scope="row"><?php esc_html_e( 'Last cleared (UTC)', 'open-wp-cache' ); ?></th><td><?php echo esc_html( null === $report['last_clear'] ? __( 'No saved clear record', 'open-wp-cache' ) : $report['last_clear']['utc'] . ' (' . $report['last_clear']['reason'] . ')' ); ?></td></tr>
				<tr><th scope="row"><?php esc_html_e( 'Estimated traffic', 'open-wp-cache' ); ?></th><td>
				<?php if ( $metrics['available'] ) : ?>
					<?php
					$counts = $metrics['data']['counts'];
					/* translators: 1: hits, 2: misses, 3: bypasses, 4: disabled requests, 5: errors. */
					echo esc_html( sprintf( __( 'Hits: %1$s · Misses: %2$s · Bypasses: %3$s · Disabled: %4$s · Errors: %5$s', 'open-wp-cache' ), number_format_i18n( $counts['hit'] ), number_format_i18n( $counts['miss'] ), number_format_i18n( $counts['bypass'] ), number_format_i18n( $counts['disabled'] ), number_format_i18n( $counts['error'] ) ) );
					?>
					<p class="description">
					<?php
					/* translators: 1: sample denominator, 2: UTC start time, 3: observations. */
					echo esc_html( sprintf( __( 'Current sampling: 1 in %1$s. Since %2$s UTC; %3$s accepted samples. Busy or failed samples are dropped; errors may overlap other counts.', 'open-wp-cache' ), number_format_i18n( $metrics['current_period'] ), gmdate( 'Y-m-d H:i:s', $metrics['data']['since'] ), number_format_i18n( $metrics['data']['samples'] ) ) );
					if ( 0 === $metrics['data']['period'] ) {
						echo ' ' . esc_html__( 'This epoch combines sampling rates.', 'open-wp-cache' );
					}
					?>
					</p>
				<?php else : ?>
					<?php esc_html_e( 'Estimates unavailable. Ask your host to provide a writable private local temp directory outside all public routes. HTML caching can continue.', 'open-wp-cache' ); ?>
				<?php endif; ?>
				</td></tr>
				<tr><th scope="row"><?php esc_html_e( 'Compression and asset headers', 'open-wp-cache' ); ?></th><td><?php echo esc_html( $report['native_gzip_available'] ? __( 'Native PHP gzip is available. Optional cached-HTML gzip is controlled below.', 'open-wp-cache' ) : __( 'Native PHP gzip is unavailable. Cached HTML uses identity encoding when acceptable; ask your host to enable PHP zlib for optional gzip.', 'open-wp-cache' ) ); ?> <span class="description"><?php esc_html_e( 'Your host controls static image, CSS and JavaScript headers and compression.', 'open-wp-cache' ); ?></span></td></tr>
			</tbody></table>
			<form method="post" action="<?php echo esc_url( admin_url( 'options.php' ) ); ?>">
				<?php settings_fields( self::GROUP ); ?>
				<?php do_settings_sections( self::PAGE ); ?>
				<?php submit_button(); ?>
			</form>
		</div>
		<?php
	}
}

<?php
/**
 * Policy-gated PHP serving and bounded, completion-checked template capture.
 *
 * @package OpenWPCache
 */

namespace OpenWPCache;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** All frontend reads/writes must pass through this policy boundary. */
final class Page_Cache {

	/**
	 * Eligible pre-render context.
	 *
	 * @var array
	 */
	private static array $context = array();
	/**
	 * Bounded retained output.
	 *
	 * @var string
	 */
	private static string $captured = '';
	/**
	 * Original WordPress-selected template.
	 *
	 * @var string
	 */
	private static string $template = '';
	/**
	 * Owned buffer level.
	 *
	 * @var int
	 */
	private static int $level = 0;
	/**
	 * Whether capture remains safe.
	 *
	 * @var bool
	 */
	private static bool $capturing = false;
	/**
	 * Whether the template returned normally.
	 *
	 * @var bool
	 */
	private static bool $finished = false;

	/** Serve after ordinary redirects, or begin capturing an eligible GET miss. */
	public static function handle(): void {
		$server  = wp_unslash( $_SERVER );
		$context = self::lookup();
		self::diagnostic( $context['status'], $context['reason'] );
		if ( 'HIT' === $context['status'] ) {
			$representation = Http::representation( $context['entry']['body'], $context['generation'], $context['settings'], $server );
			if ( null === $representation ) {
				status_header( 406 );
				self::diagnostic( 'BYPASS', 'encoding' );
				exit;
			}
			$not_modified = Http::send( $representation, $context['entry']['meta']['created'], $context['settings'], $server );
			if ( ! $not_modified && 'HEAD' !== ( $server['REQUEST_METHOD'] ?? '' ) ) {
				// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Validated public HTML data, served verbatim.
				echo $representation['body'];
			}
			exit;
		}
		if ( 'MISS' !== $context['status'] || 'GET' !== ( $server['REQUEST_METHOD'] ?? '' ) ) {
			if ( 'MISS' === $context['status'] ) {
				Http::browser_policy( $context['settings'] );
			}
			return;
		}
		self::$context   = $context;
		self::$capturing = true;
		self::$finished  = false;
		self::$captured  = '';
		if ( ! ob_start( array( self::class, 'buffer' ), 16384 ) ) {
			self::$capturing = false;
			self::diagnostic( 'BYPASS', 'capture_error' );
			return;
		}
		self::$level = ob_get_level();
		add_filter( 'template_include', array( self::class, 'wrap_template' ), PHP_INT_MAX );
	}

	/**
	 * Preserve the global template include scope while observing normal return.
	 *
	 * @param string $template WordPress-selected local template.
	 * @return string
	 */
	public static function wrap_template( string $template ): string {
		if ( ! self::$capturing || ! is_file( $template ) ) {
			return $template;
		}
		self::$template = $template;
		return __DIR__ . '/template.php';
	}

	/** Get the trusted template chosen by WordPress. @return string */
	public static function selected_template(): string {
		return self::$template;
	}

	/** Mark normal template return; WordPress owns flushing its enhancement buffer. */
	public static function complete(): void {
		$buffers  = ob_get_status( true );
		$expected = self::class . '::buffer';
		$name     = $buffers[ self::$level - 1 ]['name'] ?? '';
		if ( $expected !== $name ) {
			self::$capturing = false;
			self::diagnostic( 'BYPASS', 'capture_rejected' );
			return;
		}
		foreach ( array_slice( $buffers, self::$level ) as $buffer ) {
			if ( 'wp_finalize_template_enhancement_output_buffer' !== $buffer['name'] ) {
				self::$capturing = false;
				self::diagnostic( 'BYPASS', 'capture_rejected' );
				return;
			}
		}
		self::$finished = true;
	}

	/**
	 * Retain at most 2 MiB; on overflow/interruption return original bytes intact.
	 *
	 * @param string $chunk PHP output chunk.
	 * @param int    $phase Output-handler phase flags.
	 * @return string
	 */
	public static function buffer( string $chunk, int $phase ): string {
		if ( $phase & PHP_OUTPUT_HANDLER_CLEAN ) {
			self::$capturing = false;
			self::$captured  = '';
			self::diagnostic( 'BYPASS', 'capture_rejected' );
			return '';
		}
		$final = (bool) ( $phase & PHP_OUTPUT_HANDLER_FINAL );
		if ( ! self::$capturing || ( $phase & PHP_OUTPUT_HANDLER_FLUSH ) || ( $final && ! self::$finished ) || strlen( self::$captured ) + strlen( $chunk ) > Storage::MAX_ENTRY ) {
			$original        = self::$captured . $chunk;
			self::$captured  = '';
			self::$capturing = false;
			self::diagnostic( 'BYPASS', 'capture_rejected' );
			return $original;
		}
		self::$captured .= $chunk;
		if ( ! $final ) {
			return '';
		}
		$original       = self::$captured;
		self::$captured = '';
		try {
			$error = error_get_last();
			if ( null !== $error && in_array( $error['type'], array( E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR, E_RECOVERABLE_ERROR ), true ) ) {
				self::diagnostic( 'BYPASS', 'capture_rejected' );
				return $original;
			}
			if ( str_contains( $original, '(Open WP Cache) -->' ) ) {
				self::diagnostic( 'BYPASS', 'capture_rejected' );
				return $original;
			}
			$created        = new \DateTimeImmutable( 'now', new \DateTimeZone( 'UTC' ) );
			$stamped        = $original . "\n<!-- Cached at " . $created->format( 'Y-m-d\TH:i:s.u\Z' ) . ' (Open WP Cache) -->';
			$server         = wp_unslash( $_SERVER );
			$representation = Http::representation( $stamped, self::$context['generation'], self::$context['settings'], $server );
			if ( null === $representation ) {
				status_header( 406 );
				self::diagnostic( 'BYPASS', 'encoding' );
				return '';
			}
			if ( self::publish( self::$context, $stamped ) ) {
				self::diagnostic( 'MISS', self::$context['storage']->error() );
				$not_modified = Http::send( $representation, self::$context['storage']->published_at(), self::$context['settings'], $server );
				return $not_modified ? '' : $representation['body'];
			}
		} catch ( \Throwable $error ) {
			// Fail open: output and other components' buffers remain authoritative.
			self::diagnostic( 'BYPASS', 'capture_error' );
			return $original;
		}
		$reason = Eligibility::reason( Settings::get(), true );
		if ( '' === $reason ) {
			$reason = Eligibility::response_reason( (int) http_response_code(), headers_list(), $stamped );
		}
		if ( '' === $reason ) {
			$reason = self::$context['storage']->error();
		}
		self::diagnostic( 'BYPASS', '' === $reason ? 'capture_rejected' : $reason );
		return $original;
	}

	/**
	 * Emit only fixed diagnostics on frontend HTML; never expose storage details.
	 *
	 * @param string $status Fixed state enum.
	 * @param string $reason Fixed bypass/error enum for private diagnostics.
	 */
	public static function diagnostic( string $status, string $reason = '' ): void {
		Statistics::status( $status, $reason );
		if ( headers_sent() ) {
			return;
		}
		foreach ( headers_list() as $header ) {
			if ( preg_match( '/\AContent-Type:\s*text\/html(?:;|\s|$)/i', $header ) && ! ( http_response_code() >= 300 && http_response_code() < 400 ) ) {
				if ( in_array( $status, array( 'BYPASS', 'DISABLED' ), true ) ) {
					Http::no_store();
				}
				header( 'X-Open-WP-Cache: ' . $status );
				return;
			}
		}
		header_remove( 'X-Open-WP-Cache' );
	}

	/**
	 * Return an eligible hit/miss context, or bypass before touching stored bodies.
	 *
	 * @return array
	 */
	public static function lookup(): array {
		$settings = Settings::get();
		if ( get_option( Invalidation::ERROR_OPTION, '' ) ) {
			return array(
				'status' => 'DISABLED',
				'reason' => 'invalidation_failed',
			);
		}
		if ( defined( 'WP_CACHE' ) && WP_CACHE && file_exists( WP_CONTENT_DIR . '/advanced-cache.php' ) ) {
			return array(
				'status' => 'DISABLED',
				'reason' => 'existing_page_cache',
			);
		}
		$reason = Eligibility::reason( $settings );
		if ( '' !== $reason ) {
			return array(
				'status' => 'disabled' === $reason ? 'DISABLED' : 'BYPASS',
				'reason' => $reason,
			);
		}
		$storage = Storage::from_wordpress();
		if ( ! $storage->operational() ) {
			return array(
				'status' => 'DISABLED',
				'reason' => $storage->error(),
			);
		}
		$generation = $storage->generation();
		$site       = Cache_Key::site( get_current_blog_id(), ABSPATH );
		$path       = Eligibility::request_path();
		$key        = null !== $generation && null !== $path ? Cache_Key::entry( $site, $generation, home_url( '/' ), is_ssl() ? 'https' : 'http', $path ) : null;
		if ( null === $key || null === $generation ) {
			return array(
				'status' => 'DISABLED',
				'reason' => 'storage_invalid_generation',
			);
		}
		$entry = $storage->read( $key, $generation );
		return array(
			'status'     => null === $entry ? 'MISS' : 'HIT',
			'reason'     => '',
			'storage'    => $storage,
			'generation' => $generation,
			'key'        => $key,
			'entry'      => $entry,
			'settings'   => $settings,
		);
	}

	/**
	 * Recheck privacy and effective response intent before storing captured bytes.
	 *
	 * @param array  $context Original eligible lookup context.
	 * @param string $body Complete anonymous HTML, never a partial buffer.
	 * @return bool
	 */
	public static function publish( array $context, string $body ): bool {
		$server = wp_unslash( $_SERVER );
		if ( headers_sent() || 'GET' !== ( $server['REQUEST_METHOD'] ?? '' ) || ! isset( $context['storage'], $context['key'], $context['generation'], $context['settings'] ) || ! $context['storage'] instanceof Storage ) {
			return false;
		}
		if ( '' !== Eligibility::reason( Settings::get(), true ) || '' !== Eligibility::response_reason( (int) http_response_code(), headers_list(), $body ) ) {
			return false;
		}
		return $context['storage']->put( $context['key'], $context['generation'], $body, $context['settings']['disk_ttl'] );
	}
}

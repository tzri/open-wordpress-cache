<?php
/**
 * Conservative shared request/response policy for reads and writes.
 *
 * @package OpenWPCache
 */

namespace OpenWPCache;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Privacy guards can be narrowed by integrations, never widened by a filter. */
final class Eligibility {

	/**
	 * Evaluate the actual late WordPress request context.
	 *
	 * @param array $settings Validated settings.
	 * @param bool  $writing Whether evaluating the completed capture for publication.
	 * @return string Empty means eligible; otherwise a fixed reason code.
	 */
	public static function reason( array $settings, bool $writing = false ): string {
		if ( ! $settings['enabled'] ) {
			return 'disabled';
		}
		$scheme = is_ssl() ? 'https' : 'http';
		$server = wp_unslash( $_SERVER );
		$reason = self::http_reason( $server, $_COOKIE, home_url( '/' ), $scheme, $settings['exclusions'] );
		if ( '' !== $reason ) {
			return $reason;
		}
		// CLI has no resolved frontend query; do not call query conditionals there.
		if ( 'cli' === PHP_SAPI || ( defined( 'WP_CLI' ) && WP_CLI ) ) {
			return 'cli';
		}
		$flags  = array(
			'cli'       => 'cli' === PHP_SAPI || ( defined( 'WP_CLI' ) && WP_CLI ),
			'admin'     => is_admin(),
			'api'       => ( defined( 'REST_REQUEST' ) && REST_REQUEST ) || ( defined( 'XMLRPC_REQUEST' ) && XMLRPC_REQUEST ) || wp_is_json_request(),
			'ajax'      => wp_doing_ajax(),
			'cron'      => wp_doing_cron(),
			'logged_in' => is_user_logged_in(),
			'session'   => PHP_SESSION_ACTIVE === session_status(),
			'preview'   => is_preview() || is_customize_preview(),
			'search'    => is_search(),
			'feed'      => is_feed(),
			'sitemap'   => (bool) get_query_var( 'sitemap' ) || (bool) get_query_var( 'sitemap-stylesheet' ),
			'robots'    => is_robots() || is_favicon(),
			'trackback' => is_trackback(),
			'embed'     => is_embed(),
			'not_found' => is_404(),
			'private'   => self::private_query(),
			'commerce'  => self::commerce(),
			'opt_out'   => defined( 'DONOTCACHEPAGE' ) && DONOTCACHEPAGE,
			'output'    => ! $writing && self::unsupported_output(),
		);
		$reason = self::context_reason( $flags );
		if ( '' !== $reason ) {
			return $reason;
		}
		$reason = self::response_reason( (int) http_response_code(), headers_list() );
		if ( '' !== $reason ) {
			return $reason;
		}
		$path = rawurldecode( $server['REQUEST_URI'] );
		/**
		 * Narrow public eligibility for dynamic routes or anonymous personalization.
		 *
		 * Returning anything other than true bypasses reads and writes. Built-in
		 * safety checks run first and cannot be overridden with this filter.
		 *
		 * @param bool   $eligible Whether this otherwise-public request is eligible.
		 * @param string $path Once-decoded validated path, without a query string.
		 */
		return true === apply_filters( 'open_wp_cache_eligible', true, $path ) ? '' : 'filter';
	}

	/**
	 * Return the exact validated path after undoing WordPress magic slashes.
	 *
	 * @return string|null
	 */
	public static function request_path(): ?string {
		$server = wp_unslash( $_SERVER );
		$path   = $server['REQUEST_URI'] ?? null;
		return is_string( $path ) && Cache_Key::valid_path( $path ) ? $path : null;
	}

	/**
	 * Pure HTTP/origin/path checks, also used by table-driven tests.
	 *
	 * @param array  $server Actual PHP server variables.
	 * @param array  $cookies Parsed cookies.
	 * @param string $home Trusted configured home URL.
	 * @param string $scheme Trusted WordPress scheme.
	 * @param string $exclusions Saved literal exclusions.
	 * @return string
	 */
	public static function http_reason( array $server, array $cookies, string $home, string $scheme, string $exclusions ): string {
		if ( ! in_array( $server['REQUEST_METHOD'] ?? '', array( 'GET', 'HEAD' ), true ) ) {
			return 'method';
		}
		foreach ( array( 'HTTP_AUTHORIZATION', 'REDIRECT_HTTP_AUTHORIZATION', 'PHP_AUTH_USER', 'PHP_AUTH_PW', 'PHP_AUTH_DIGEST', 'HTTP_X_WP_NONCE' ) as $header ) {
			// Standard WordPress rewrites create empty CGI authorization placeholders.
			if ( isset( $server[ $header ] ) && '' !== $server[ $header ] ) {
				return 'authorization';
			}
		}
		if ( $cookies || ( isset( $server['HTTP_COOKIE'] ) && '' !== $server['HTTP_COOKIE'] ) ) {
			return 'cookie';
		}
		if ( ! empty( $server['CONTENT_LENGTH'] ) || isset( $server['HTTP_TRANSFER_ENCODING'] ) ) {
			return 'request_body';
		}
		if ( isset( $server['HTTP_X_REQUESTED_WITH'] ) ) {
			return 'ajax';
		}
		foreach ( array( 'HTTP_RANGE', 'HTTP_IF_RANGE', 'HTTP_IF_MATCH', 'HTTP_IF_UNMODIFIED_SINCE' ) as $condition ) {
			if ( isset( $server[ $condition ] ) ) {
				return 'unsupported_condition';
			}
		}
		if ( preg_match( '/no-cache|no-store|max-age\s*=\s*0/i', $server['HTTP_CACHE_CONTROL'] ?? '' ) || preg_match( '/no-cache/i', $server['HTTP_PRAGMA'] ?? '' ) ) {
			return 'request_policy';
		}
		$path = $server['REQUEST_URI'] ?? '';
		if ( ! is_string( $path ) || ! is_string( $server['QUERY_STRING'] ?? '' ) ) {
			return 'target';
		}
		if ( '' !== ( $server['QUERY_STRING'] ?? '' ) || str_contains( $path, '?' ) ) {
			return 'query';
		}
		if ( ! Cache_Key::valid_path( $path ) ) {
			return 'target';
		}
		$expected = Cache_Key::authority( $home, $scheme );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.parse_url_parse_url -- Pure origin policy.
		if ( null === $expected || parse_url( $home, PHP_URL_SCHEME ) !== $scheme ) {
			return 'origin';
		}
		$host = $server['HTTP_HOST'] ?? '';
		if ( ! is_string( $host ) || ! preg_match( '/\A(?:\[[a-f0-9:]+\]|[a-z0-9.-]+)(?::[0-9]{1,5})?\z/iD', $host ) || Cache_Key::authority( $scheme . '://' . $host, $scheme ) !== $expected ) {
			return 'host';
		}
		$decoded = rawurldecode( $path );
		$lines   = Settings::exclusion_lines( $exclusions );
		if ( null === $lines ) {
			return 'invalid_exclusions';
		}
		foreach ( $lines as $line ) {
			if ( str_contains( $decoded, $line ) ) {
				return 'exclusion';
			}
		}
		// phpcs:ignore WordPress.WP.AlternativeFunctions.parse_url_parse_url -- Pure path policy.
		$base     = rawurldecode( parse_url( $home, PHP_URL_PATH ) ?? '/' );
		$relative = str_starts_with( $decoded, rtrim( $base, '/' ) . '/' ) ? substr( $decoded, strlen( rtrim( $base, '/' ) ) ) : $decoded;
		$first    = strtolower( explode( '/', ltrim( $relative, '/' ) )[0] );
		if ( in_array( $first, array( 'wp-admin', 'wp-login.php', 'wp-register.php', 'wp-signup.php', 'wp-activate.php', 'wp-json', 'xmlrpc.php', 'wp-cron.php', 'robots.txt', 'favicon.ico', 'feed', 'trackback' ), true ) || preg_match( '/\A(?:wp-sitemap(?:-[a-z0-9_-]+)?|sitemap(?:_index)?)\.xml\z/iD', $first ) ) {
			return 'builtin';
		}
		if ( in_array( $first, array( 'cart', 'checkout', 'my-account', 'account', 'order-pay', 'order-received', 'wc-api' ), true ) ) {
			return 'commerce_path';
		}
		return '';
	}

	/**
	 * Evaluate resolved WordPress flags without changing their privacy boundary.
	 *
	 * @param array $flags Named fixed boolean flags.
	 * @return string
	 */
	public static function context_reason( array $flags ): string {
		foreach ( array( 'cli', 'admin', 'api', 'ajax', 'cron', 'logged_in', 'session', 'preview', 'search', 'feed', 'sitemap', 'robots', 'trackback', 'embed', 'not_found', 'private', 'commerce', 'opt_out', 'output' ) as $reason ) {
			if ( ! empty( $flags[ $reason ] ) ) {
				return $reason;
			}
		}
		return '';
	}

	/**
	 * Check effective response restrictions before a read and again before a write.
	 *
	 * @param int         $status Response status.
	 * @param array       $headers Actual PHP response headers.
	 * @param string|null $body Completed uncompressed HTML, when writing.
	 * @return string
	 */
	public static function response_reason( int $status, array $headers, ?string $body = null ): string {
		if ( 200 !== $status ) {
			return 'status';
		}
		$html = false;
		foreach ( $headers as $header ) {
			$parts = explode( ':', $header, 2 );
			if ( 2 !== count( $parts ) ) {
				continue;
			}
			$name  = strtolower( trim( $parts[0] ) );
			$value = trim( $parts[1] );
			if ( 'set-cookie' === $name || 'content-disposition' === $name || 'content-encoding' === $name || 'location' === $name ) {
				return 'response_private';
			}
			if ( 'cache-control' === $name && preg_match( '/\b(?:private|no-store|no-cache)\b/i', $value ) ) {
				return 'response_private';
			}
			if ( 'pragma' === $name && preg_match( '/\bno-cache\b/i', $value ) ) {
				return 'response_private';
			}
			if ( 'vary' === $name ) {
				foreach ( explode( ',', strtolower( $value ) ) as $variation ) {
					if ( ! in_array( trim( $variation ), array( '', 'accept-encoding' ), true ) ) {
						return 'response_vary';
					}
				}
			}
			if ( 'content-type' === $name ) {
				$html = 1 === preg_match( '/\Atext\/html(?:\s*;\s*charset\s*=\s*["\']?utf-8["\']?)?\s*\z/iD', $value );
			}
		}
		if ( ! $html ) {
			return 'content_type';
		}
		if ( null !== $body && ( strlen( $body ) > Storage::MAX_ENTRY || '' === $body || str_contains( $body, "\0" ) || ! preg_match( '//u', $body ) || preg_match( '/(?:_wpnonce|_ajax_nonce|wpApiSettings)/i', $body ) ) ) {
			return 'response_body';
		}
		return '';
	}

	/**
	 * Reject protected/nonpublic main-query content even when its cookie unlocks it.
	 *
	 * @return bool
	 */
	private static function private_query(): bool {
		global $wp_query;
		if ( is_attachment() || ! $wp_query instanceof \WP_Query || ! is_array( $wp_query->posts ) || count( $wp_query->posts ) > 100 ) {
			return true;
		}
		foreach ( $wp_query->posts as $post ) {
			if ( ! $post instanceof \WP_Post || 'publish' !== $post->post_status || '' !== $post->post_password || ! is_post_type_viewable( $post->post_type ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Honor configured commerce page IDs and endpoints when WooCommerce is present.
	 *
	 * @return bool
	 */
	private static function commerce(): bool {
		return ( function_exists( 'is_cart' ) && is_cart() )
			|| ( function_exists( 'is_checkout' ) && is_checkout() )
			|| ( function_exists( 'is_account_page' ) && is_account_page() )
			|| ( function_exists( 'is_wc_endpoint_url' ) && is_wc_endpoint_url() );
	}

	/** Existing output or transforming handlers cannot be replayed safely. */
	private static function unsupported_output(): bool {
		if ( headers_sent() || ob_get_level() > 32 || ini_get( 'zlib.output_compression' ) ) {
			return true;
		}
		foreach ( ob_get_status( true ) as $buffer ) {
			if ( $buffer['buffer_used'] > 0 || 'default output handler' !== $buffer['name'] ) {
				return true;
			}
		}
		return false;
	}
}

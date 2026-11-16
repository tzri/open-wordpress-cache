<?php
/**
 * Owned HTML browser policy, representation negotiation and conditional GET.
 *
 * @package OpenWPCache
 */

namespace OpenWPCache;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** HTTP behavior is applied only after the shared privacy boundary. */
final class Http {
	/**
	 * Apache supplies Date from request start; Nginx supplies it when responding.
	 *
	 * @param array $server Trusted SAPI server context, not forwarded headers.
	 * @param int   $now Current origin clock.
	 * @return int
	 */
	public static function origin_time( array $server, int $now ): int {
		if ( 0 === stripos( $server['SERVER_SOFTWARE'] ?? '', 'Apache' ) && isset( $server['REQUEST_TIME'] ) && is_int( $server['REQUEST_TIME'] ) && $server['REQUEST_TIME'] > 0 ) {
			return min( $now, $server['REQUEST_TIME'] );
		}
		return $now;
	}
	/**
	 * Choose an acceptable available coding; null means neither is acceptable.
	 *
	 * @param string $accept Accept-Encoding field, empty means identity preference.
	 * @param bool   $gzip Whether a native encoder is available and enabled.
	 * @return string|null
	 */
	public static function encoding( string $accept, bool $gzip ): ?string {
		if ( '' === trim( $accept ) || strlen( $accept ) > 4096 ) {
			return 'identity';
		}
		$weights = array();
		$items   = explode( ',', strtolower( $accept ) );
		if ( count( $items ) > 32 ) {
			return 'identity';
		}
		foreach ( $items as $item ) {
			if ( '' === trim( $item ) ) {
				continue;
			}
			if ( ! preg_match( '/\A\s*([a-z0-9_*.-]+)\s*(?:;\s*q\s*=\s*(0(?:\.[0-9]{0,3})?|1(?:\.0{0,3})?))?\s*\z/D', $item, $match ) ) {
				return 'identity';
			}
			$weight               = isset( $match[2] ) && '' !== $match[2] ? (float) $match[2] : 1.0;
			$weights[ $match[1] ] = isset( $weights[ $match[1] ] ) ? min( $weights[ $match[1] ], $weight ) : $weight;
		}
		$identity = $weights['identity'] ?? ( 0.0 === ( $weights['*'] ?? 1.0 ) ? 0.0 : 1.0 );
		$encoded  = $gzip ? ( $weights['gzip'] ?? $weights['*'] ?? 0.0 ) : 0.0;
		if ( $encoded > 0 && $encoded >= $identity ) {
			return 'gzip';
		}
		return $identity > 0 ? 'identity' : null;
	}

	/**
	 * Prepare bytes and a generation/representation-specific strong entity tag.
	 *
	 * @param string $body Validated public HTML.
	 * @param string $generation Captured generation identity.
	 * @param array  $settings Validated settings.
	 * @param array  $server Unslashed server context.
	 * @return array|null
	 */
	public static function representation( string $body, string $generation, array $settings, array $server ): ?array {
		$accept   = $server['HTTP_ACCEPT_ENCODING'] ?? '';
		$can_gzip = $settings['gzip'] && function_exists( 'gzencode' ) && ! ini_get( 'zlib.output_compression' );
		$encoding = self::encoding( $accept, $can_gzip );
		if ( 'gzip' === $encoding ) {
			$compressed = gzencode( $body, 6 );
			if ( false === $compressed ) {
				$encoding = self::encoding( $accept, false );
			} else {
				$body = $compressed;
			}
		}
		if ( null === $encoding ) {
			return null;
		}
		return array(
			'body'     => $body,
			'encoding' => $encoding,
			'etag'     => '"owc-' . hash( 'sha256', $generation . "\0" . $encoding . "\0" . $body ) . '"',
		);
	}

	/**
	 * Parse all three HTTP-date forms without accepting relative/normalized dates.
	 *
	 * @param string $value HTTP-date field.
	 * @param int    $now Origin clock for obsolete two-digit-year interpretation.
	 * @return int|null
	 */
	public static function timestamp( string $value, int $now ): ?int {
		$value = trim( $value, " \t" );
		if ( strlen( $value ) > 100 ) {
			return null;
		}
		if ( preg_match( '/\A([A-Z][a-z]{2}), ([0-9]{2}) ([A-Z][a-z]{2}) ([0-9]{4}) ([0-9]{2}):([0-9]{2}):([0-9]{2}) GMT\z/D', $value, $parts ) ) {
			list( , $weekday, $day, $month, $year, $hour, $minute, $second ) = $parts;
		} elseif ( preg_match( '/\A([A-Z][a-z]+), ([0-9]{2})-([A-Z][a-z]{2})-([0-9]{2}) ([0-9]{2}):([0-9]{2}):([0-9]{2}) GMT\z/D', $value, $parts ) ) {
			list( , $weekday, $day, $month, $year, $hour, $minute, $second ) = $parts;
			$full_days = array( 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday', 'Sunday' );
			if ( ! in_array( $weekday, $full_days, true ) ) {
				return null;
			}
			$year = (int) ( floor( (int) gmdate( 'Y', $now ) / 100 ) * 100 ) + (int) $year;
			if ( $year > (int) gmdate( 'Y', $now ) + 50 ) {
				$year -= 100;
			}
		} elseif ( preg_match( '/\A([A-Z][a-z]{2}) ([A-Z][a-z]{2}) ([ 0-9][0-9]) ([0-9]{2}):([0-9]{2}):([0-9]{2}) ([0-9]{4})\z/D', $value, $parts ) ) {
			list( , $weekday, $month, $day, $hour, $minute, $second, $year ) = $parts;
		} else {
			return null;
		}
		$months = array(
			'Jan' => 1,
			'Feb' => 2,
			'Mar' => 3,
			'Apr' => 4,
			'May' => 5,
			'Jun' => 6,
			'Jul' => 7,
			'Aug' => 8,
			'Sep' => 9,
			'Oct' => 10,
			'Nov' => 11,
			'Dec' => 12,
		);
		$month  = $months[ $month ] ?? 0;
		if ( ! checkdate( $month, (int) $day, (int) $year ) || (int) $hour > 23 || (int) $minute > 59 || (int) $second > 60 ) {
			return null;
		}
		$date = \DateTimeImmutable::createFromFormat( '!Y-n-j G:i:s', sprintf( '%04d-%d-%d %d:%02d:%02d', $year, $month, $day, $hour, $minute, min( 59, (int) $second ) ), new \DateTimeZone( 'UTC' ) );
		if ( false === $date || $date->format( 'D' ) !== substr( $weekday, 0, 3 ) ) {
			return null;
		}
		return $date->getTimestamp() + ( 60 === (int) $second ? 1 : 0 );
	}

	/**
	 * Evaluate supported GET/HEAD conditions only for a fresh eligible representation.
	 *
	 * @param array  $server Request context.
	 * @param string $etag Current quoted strong tag.
	 * @param int    $created Representation creation second.
	 * @param int    $now Origin clock.
	 * @return bool
	 */
	public static function not_modified( array $server, string $etag, int $created, int $now ): bool {
		if ( ! in_array( $server['REQUEST_METHOD'] ?? '', array( 'GET', 'HEAD' ), true ) ) {
			return false;
		}
		if ( array_key_exists( 'HTTP_IF_NONE_MATCH', $server ) ) {
			$value = $server['HTTP_IF_NONE_MATCH'];
			if ( ! is_string( $value ) || strlen( $value ) > 8192 ) {
				return false;
			}
			if ( '*' === trim( $value, " \t" ) ) {
				return true;
			}
			$matched = false;
			$offset  = 0;
			$length  = strlen( $value );
			while ( $offset < $length ) {
				while ( $offset < $length && str_contains( " \t,", $value[ $offset ] ) ) {
					++$offset;
				}
				if ( $offset === $length ) {
					break;
				}
				if ( ! preg_match( '~\G(?:W/)?"([\x21\x23-\x7E\x80-\xFF]*)"~', $value, $tag, 0, $offset ) ) {
					return false;
				}
				$matched = $matched || '"' . $tag[1] . '"' === $etag;
				$offset += strlen( $tag[0] );
				while ( $offset < $length && str_contains( " \t", $value[ $offset ] ) ) {
					++$offset;
				}
				if ( $offset < $length && ',' !== $value[ $offset ] ) {
					return false;
				}
			}
			return $matched;
		}
		$date = self::timestamp( $server['HTTP_IF_MODIFIED_SINCE'] ?? '', $now );
		// Equality is ambiguous at HTTP-date's one-second precision. Prefer an extra
		// 200 to a false 304 after two changes in that second; exact ETags take priority.
		return null !== $date && $created < $date;
	}

	/**
	 * Merge Vary field tokens without replacing upstream values.
	 *
	 * @param array $headers Current header list.
	 * @return string
	 */
	public static function vary( array $headers ): string {
		$tokens = array();
		foreach ( $headers as $header ) {
			if ( 0 === stripos( $header, 'Vary:' ) ) {
				foreach ( explode( ',', substr( $header, 5 ) ) as $token ) {
					$token = trim( $token );
					if ( '' !== $token ) {
						$tokens[ strtolower( $token ) ] = $token;
					}
				}
			}
		}
		if ( ! isset( $tokens['accept-encoding'] ) ) {
			$tokens['accept-encoding'] = 'Accept-Encoding';
		}
		return implode( ', ', $tokens );
	}

	/**
	 * Apply private browser freshness, capped by existing upstream freshness.
	 *
	 * @param array $settings Validated settings.
	 */
	public static function browser_policy( array $settings ): void {
		$now       = self::origin_time( wp_unslash( $_SERVER ), time() );
		$ttl       = $settings['browser_ttl'];
		$expires   = $now + $ttl;
		$transform = ', no-transform';
		foreach ( headers_list() as $header ) {
			if ( 0 === stripos( $header, 'Cache-Control:' ) ) {
				if ( preg_match_all( '/\bmax-age\s*=\s*"?([0-9]+)/i', $header, $matches ) ) {
					foreach ( $matches[1] as $age ) {
						$ttl = min( $ttl, (int) $age );
					}
				} elseif ( stripos( $header, 'max-age' ) !== false ) {
					$ttl = 0;
				}
				if ( preg_match( '/\bno-transform\b/i', $header ) ) {
					$transform = ', no-transform';
				}
			}
			if ( 0 === stripos( $header, 'Expires:' ) ) {
				$old = self::timestamp( trim( substr( $header, 8 ) ), $now );
				if ( null !== $old ) {
					$expires = min( $expires, $old );
					$ttl     = min( $ttl, max( 0, $old - $now ) );
				} else {
					$expires = 0;
					$ttl     = 0;
				}
			}
		}
		header( 'Date: ' . gmdate( 'D, d M Y H:i:s \G\M\T', $now ) );
		header( 'Cache-Control: private, max-age=' . $ttl . ', must-revalidate' . $transform );
		header( 'Expires: ' . gmdate( 'D, d M Y H:i:s \G\M\T', min( $expires, $now + $ttl ) ) );
	}

	/** Apply restrictive policy to relevant bypassed HTML, preserving stricter intent. */
	public static function no_store(): void {
		$existing = array();
		foreach ( headers_list() as $header ) {
			if ( 0 === stripos( $header, 'Cache-Control:' ) ) {
				$existing[] = trim( substr( $header, 14 ) );
			}
		}
		$existing = implode( ', ', $existing );
		$policy   = 'private, no-store, no-cache, max-age=0, must-revalidate';
		if ( ! str_contains( $existing, $policy ) ) {
			$existing = '' === $existing ? $policy : $existing . ', ' . $policy;
		}
		header( 'Cache-Control: ' . $existing );
		header( 'Pragma: no-cache' );
		header( 'Expires: Thu, 01 Jan 1970 00:00:00 GMT' );
		header_remove( 'ETag' );
		header_remove( 'Last-Modified' );
	}

	/**
	 * Send owned representation headers. True means a bodyless 304 response.
	 *
	 * @param array $representation Prepared bytes/encoding/tag.
	 * @param int   $created Actual successful publication time.
	 * @param array $settings Validated settings.
	 * @param array $server Unslashed request context.
	 * @return bool
	 */
	public static function send( array $representation, int $created, array $settings, array $server ): bool {
		// mod_php exposes a request-local API for Apache output filters. Do not
		// rewrite server configuration or affect bypasses/static assets. Other
		// downstream servers/proxies must honor the no-transform response policy.
		if ( function_exists( 'apache_setenv' ) ) {
			// phpcs:disable WordPress.PHP.DiscouragedPHPFunctions.runtime_configuration_apache_setenv -- Guarded per-request filter flags preserve encoded bytes/validators; no persistent configuration is changed.
			apache_setenv( 'no-gzip', '1' );
			apache_setenv( 'no-brotli', '1' );
			// phpcs:enable WordPress.PHP.DiscouragedPHPFunctions.runtime_configuration_apache_setenv
		}
		self::browser_policy( $settings );
		header( 'Vary: ' . self::vary( headers_list() ) );
		header( 'ETag: ' . $representation['etag'] );
		header( 'Last-Modified: ' . gmdate( 'D, d M Y H:i:s \G\M\T', min( self::origin_time( $server, time() ), $created ) ) );
		if ( self::not_modified( $server, $representation['etag'], $created, time() ) ) {
			status_header( 304 );
			header_remove( 'Content-Length' );
			header_remove( 'Content-Encoding' );
			return true;
		}
		header( 'Content-Length: ' . strlen( $representation['body'] ) );
		if ( 'gzip' === $representation['encoding'] ) {
			header( 'Content-Encoding: gzip' );
		}
		return false;
	}
}

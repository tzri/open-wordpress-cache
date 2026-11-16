<?php
/**
 * Opaque identities from trusted configuration and an unambiguous request path.
 *
 * @package OpenWPCache
 */

namespace OpenWPCache;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Pure key policy; incoming Host and proxy headers are never key authorities. */
final class Cache_Key {

	/**
	 * Identify one blog in one WordPress installation without publishing its path.
	 *
	 * @param int    $blog_id Blog identifier.
	 * @param string $install Installation identifier, normally ABSPATH.
	 * @return string
	 */
	public static function site( int $blog_id, string $install ): string {
		return hash( 'sha256', "owc-site-v1\0" . $install . "\0" . $blog_id );
	}

	/**
	 * Canonical configured authority including the effective port.
	 *
	 * @param string $home Configured home URL, never incoming Host.
	 * @param string $scheme Trusted WordPress request scheme.
	 * @return string|null
	 */
	public static function authority( string $home, string $scheme ): ?string {
		if ( ! in_array( $scheme, array( 'http', 'https' ), true ) ) {
			return null;
		}
		try {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.parse_url_parse_url -- Pure policy also tested outside WordPress.
			$url = parse_url( $home );
		} catch ( \ValueError $error ) {
			return null;
		}
		if ( ! is_array( $url ) || empty( $url['host'] ) || ! in_array( $url['scheme'] ?? '', array( 'http', 'https' ), true ) || isset( $url['user'] ) || isset( $url['pass'] ) || isset( $url['query'] ) || isset( $url['fragment'] ) ) {
			return null;
		}
		$host = strtolower( $url['host'] );
		if ( ! preg_match( '/\A[a-z0-9.\[\]:-]+\z/D', $host ) ) {
			return null;
		}
		$port = $url['port'] ?? ( 'https' === $scheme ? 443 : 80 );
		return $host . ':' . $port;
	}

	/**
	 * Validate without normalizing meaningful path/encoding differences.
	 *
	 * @param string $path Raw path only, with no query string.
	 * @return bool
	 */
	public static function valid_path( string $path ): bool {
		if ( '' === $path || '/' !== $path[0] || strlen( $path ) > 2048 || preg_match( '/[\x00-\x20\x7f?#\\\\]/', $path ) || str_contains( $path, '//' ) || preg_match( '/%(?![a-f0-9]{2})/i', $path ) || preg_match( '/%(?:2f|5c|3f|23|25)/i', $path ) ) {
			return false;
		}
		$decoded = rawurldecode( $path );
		if ( ! preg_match( '//u', $decoded ) || preg_match( '/[\x00-\x20\x7f]/', $decoded ) ) {
			return false;
		}
		foreach ( explode( '/', $decoded ) as $segment ) {
			if ( '.' === $segment || '..' === $segment ) {
				return false;
			}
		}
		return true;
	}

	/**
	 * Hash the complete identity, including the active generation.
	 *
	 * @param string $site Site hash.
	 * @param string $generation Captured generation.
	 * @param string $home Configured home URL.
	 * @param string $scheme Trusted scheme.
	 * @param string $path Exact validated request path.
	 * @return string|null
	 */
	public static function entry( string $site, string $generation, string $home, string $scheme, string $path ): ?string {
		$authority = self::authority( $home, $scheme );
		if ( ! preg_match( '/\A[a-f0-9]{64}\z/D', $site ) || ! preg_match( '/\A[a-f0-9]{32}\z/D', $generation ) || null === $authority || ! self::valid_path( $path ) ) {
			return null;
		}
		return hash( 'sha256', implode( "\0", array( 'owc-entry-v1', $site, $generation, $scheme, $authority, $path ) ) );
	}
}

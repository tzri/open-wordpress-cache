<?php
/**
 * Bounded site-local configuration.
 *
 * @package OpenWPCache
 */

namespace OpenWPCache;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Settings shared by activation and the cache services. */
final class Settings {

	const OPTION          = 'open_wp_cache_settings';
	const MIN_DISK_TTL    = 60;
	const MAX_DISK_TTL    = 604800;
	const MAX_BROWSER_TTL = 3600;

	/**
	 * Return the public configuration defaults.
	 *
	 * @return array
	 */
	public static function defaults(): array {
		return array(
			'enabled'     => true,
			'disk_ttl'    => 3600,
			'browser_ttl' => 0,
			'gzip'        => false,
			'exclusions'  => '',
		);
	}

	/**
	 * Read settings without trusting malformed saved values.
	 *
	 * @return array
	 */
	public static function get(): array {
		$saved = get_option( self::OPTION, false );
		return self::normalize( is_array( $saved ) ? $saved : array( 'enabled' => false ) );
	}

	/**
	 * Bound settings received from persistence or an authorized admin request.
	 *
	 * @param mixed $input Raw settings.
	 * @return array
	 */
	public static function normalize( $input ): array {
		$defaults = self::defaults();
		$input    = is_array( $input ) ? $input : array();
		$settings = array_intersect_key( array_merge( $defaults, $input ), $defaults );
		foreach ( array( 'enabled', 'gzip' ) as $key ) {
			$settings[ $key ] = in_array( $settings[ $key ], array( true, 1, '1' ), true );
		}
		foreach ( array( 'disk_ttl', 'browser_ttl' ) as $key ) {
			if ( ! is_scalar( $settings[ $key ] ) || ! is_numeric( $settings[ $key ] ) ) {
				$settings[ $key ] = $defaults[ $key ];
			}
		}
		$settings['disk_ttl']    = max( self::MIN_DISK_TTL, min( self::MAX_DISK_TTL, (int) $settings['disk_ttl'] ) );
		$settings['browser_ttl'] = max( 0, min( self::MAX_BROWSER_TTL, (int) $settings['browser_ttl'] ) );
		$settings['exclusions']  = is_string( $settings['exclusions'] ) ? $settings['exclusions'] : '';
		if ( ( isset( $input['exclusions'] ) && ! is_string( $input['exclusions'] ) ) || null === self::exclusion_lines( $settings['exclusions'] ) ) {
			// A corrupt exclusion list must never silently enable caching of a private path.
			$settings['enabled']    = false;
			$settings['exclusions'] = '';
		}
		return $settings;
	}

	/**
	 * Parse bounded literal exclusions using the same once-decoded path semantics.
	 *
	 * @param string $text Newline-separated substrings, not regular expressions.
	 * @return array|null Null means invalid; callers must fail closed or retain old settings.
	 */
	public static function exclusion_lines( string $text ): ?array {
		if ( strlen( $text ) > 8192 || ! preg_match( '//u', $text ) ) {
			return null;
		}
		$lines = array();
		foreach ( preg_split( '/\r\n|\r|\n/', $text ) as $line ) {
			if ( preg_match( '/[\x00-\x08\x0b\x0c\x0e-\x1f\x7f]/', $line ) ) {
				return null;
			}
			$line = trim( $line );
			if ( '' === $line ) {
				continue;
			}
			if ( strlen( $line ) > 256 || count( $lines ) >= 50 || preg_match( '/%(?![a-f0-9]{2})/i', $line ) ) {
				return null;
			}
			$decoded = rawurldecode( $line );
			if ( ! preg_match( '//u', $decoded ) || preg_match( '/[\x00-\x1f\x7f]/', $decoded ) ) {
				return null;
			}
			$lines[] = $decoded;
		}
		return $lines;
	}
}

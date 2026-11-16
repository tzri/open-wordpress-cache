<?php
/**
 * Private sampled aggregates and bounded fixed-enum diagnostics.
 *
 * @package OpenWPCache
 */

namespace OpenWPCache;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// phpcs:disable WordPress.WP.AlternativeFunctions -- Private local flock/atomic-rename semantics cannot use remote filesystem transports.
// phpcs:disable WordPress.PHP.NoSilencedErrors -- Optional diagnostics must never disclose paths or break the visitor response on expected IO failure.

/** No visitor values enter this service; its files must be outside public roots. */
final class Statistics {
	private const LIMIT     = 2000000000;
	private const MAX_BYTES = 16384;
	private const COUNTERS  = array( 'hit', 'miss', 'bypass', 'disabled', 'error' );
	private const REASONS   = array(
		'',
		'other',
		'disabled',
		'method',
		'authorization',
		'cookie',
		'request_body',
		'ajax',
		'request_policy',
		'query',
		'target',
		'origin',
		'host',
		'invalid_exclusions',
		'exclusion',
		'builtin',
		'commerce_path',
		'cli',
		'admin',
		'api',
		'cron',
		'logged_in',
		'session',
		'preview',
		'search',
		'feed',
		'sitemap',
		'robots',
		'trackback',
		'embed',
		'not_found',
		'private',
		'commerce',
		'opt_out',
		'output',
		'status',
		'response_private',
		'response_vary',
		'content_type',
		'response_body',
		'filter',
		'unsupported_condition',
		'encoding',
		'capture_rejected',
		'capture_error',
		'existing_page_cache',
		'uploads_unavailable',
		'invalidation_failed',
		'manual',
		'cli_clear',
		'settings',
		'post',
		'comment',
		'taxonomy',
		'appearance',
		'option',
		'deactivation',
		'storage_io',
		'storage_not_local',
		'storage_unwritable',
		'storage_invalid_entry',
		'storage_generation_changed',
		'storage_invalid_usage',
		'storage_full',
		'storage_foreign_directory',
		'storage_unsafe_path',
		'storage_randomness',
		'storage_lock',
		'storage_invalid_generation',
		'storage_unavailable',
		'metrics_reset',
	);
	/**
	 * Final logical frontend outcome, collected after WordPress flushes output.
	 *
	 * @var array|null
	 */
	private static ?array $outcome = null;
	/**
	 * Private canonical base, resolved lazily only for selected samples.
	 *
	 * @var string
	 */
	private string $base;
	/**
	 * Opaque site identity.
	 *
	 * @var string
	 */
	private string $site;
	/**
	 * Known public roots.
	 *
	 * @var array
	 */
	private array $public_roots;
	/**
	 * Sampling denominator.
	 *
	 * @var int
	 */
	private int $period;
	/**
	 * Deterministic injection for tests; production uses native random_int.
	 *
	 * @var \Closure
	 */
	private \Closure $sampler;
	/**
	 * UTC Unix clock.
	 *
	 * @var \Closure
	 */
	private \Closure $clock;

	/**
	 * Prepare a lazy private store; no IO occurs until a sample/status operation.
	 *
	 * @param string        $base Existing private parent, normally the system temp directory.
	 * @param string        $site Hashed installation/blog identity.
	 * @param array         $public_roots Additional public upload/document roots.
	 * @param int           $period Sampling denominator, 100 by default.
	 * @param callable|null $sampler Optional deterministic sampling decision.
	 * @param callable|null $clock Optional deterministic UTC Unix clock.
	 */
	public function __construct( string $base, string $site, array $public_roots = array(), int $period = 100, ?callable $sampler = null, ?callable $clock = null ) {
		$server             = function_exists( 'wp_unslash' ) ? wp_unslash( $_SERVER ) : $_SERVER;
		$this->base         = rtrim( $base, '/\\' );
		$this->site         = $site;
		$this->public_roots = array_merge( $public_roots, array( ABSPATH, defined( 'WP_CONTENT_DIR' ) ? WP_CONTENT_DIR : '', $server['DOCUMENT_ROOT'] ?? '' ) );
		$this->period       = max( 1, min( 10000, $period ) );
		$this->sampler      = \Closure::fromCallable( $sampler ?? fn() => 1 === random_int( 1, $this->period ) );
		$this->clock        = \Closure::fromCallable( $clock ?? 'time' );
	}

	/** Resolve per-site private storage; integrations may inject this same service. */
	public static function from_wordpress(): self {
		$uploads = wp_upload_dir( null, false );
		$base    = defined( 'OPEN_WP_CACHE_PRIVATE_DIR' ) ? OPEN_WP_CACHE_PRIVATE_DIR : sys_get_temp_dir();
		$store   = new self( is_string( $base ) ? $base : '', Cache_Key::site( get_current_blog_id(), ABSPATH ), array( $uploads['basedir'] ?? '' ) );
		$chosen  = apply_filters( 'open_wp_cache_statistics', $store );
		return $chosen instanceof self ? $chosen : $store;
	}

	/** Collect once after WordPress's shutdown hook has finalized owned capture. */
	public static function register(): void {
		if ( 'cli' !== PHP_SAPI && ! ( defined( 'WP_CLI' ) && WP_CLI ) ) {
			register_shutdown_function( array( self::class, 'flush_outcome' ) );
		}
	}

	/**
	 * Update only fixed in-memory state; headers may already have been sent.
	 *
	 * @param string $status Fixed frontend diagnostic state.
	 * @param string $reason Fixed bypass/error enum, never caller text.
	 */
	public static function status( string $status, string $reason = '' ): void {
		self::$outcome = array(
			'status' => $status,
			'reason' => self::reason( $reason ),
		);
	}

	/** No exception or optional diagnostic failure may affect the response. */
	public static function flush_outcome(): void {
		if ( false === get_option( Settings::OPTION, false ) ) {
			return;
		}
		if ( null === self::$outcome ) {
			return;
		}
		try {
			self::from_wordpress()->record( self::$outcome['status'], self::$outcome['reason'] );
		} catch ( \Throwable $error ) {
			return;
		}
	}

	/**
	 * Record a selected outcome with inverse-probability weighted estimates.
	 *
	 * @param string $status Frontend state enum.
	 * @param string $reason Fixed reason enum.
	 * @return bool Whether a sample was persisted (false also means not selected).
	 */
	public function record( string $status, string $reason = '' ): bool {
		try {
			if ( ! ( $this->sampler )() ) {
				return false;
			}
			$reason = self::reason( $reason );
			$key    = array(
				'HIT'      => 'hit',
				'MISS'     => 'miss',
				'BYPASS'   => 'bypass',
				'DISABLED' => 'disabled',
			)[ $status ] ?? 'bypass';
			return true === $this->operate(
				function ( array $state ) use ( $key, $reason ): array {
					$state['counts'][ $key ] = min( self::LIMIT, $state['counts'][ $key ] + $this->period );
					$state['samples']        = min( self::LIMIT, $state['samples'] + 1 );
					$state['period']         = 0 === $state['samples'] - 1 || $state['period'] === $this->period ? $this->period : 0;
					if ( '' !== $reason ) {
						$state['reasons'][ $reason ] = min( self::LIMIT, ( $state['reasons'][ $reason ] ?? 0 ) + $this->period );
					}
					if ( str_starts_with( $reason, 'storage_' ) || in_array( $reason, array( 'capture_error', 'invalidation_failed', 'existing_page_cache', 'uploads_unavailable' ), true ) ) {
						$state['counts']['error'] = min( self::LIMIT, $state['counts']['error'] + $this->period );
						$state                    = $this->append( $state, 'error', $reason );
					}
					return $state;
				}
			);
		} catch ( \Throwable $error ) {
			return false;
		}
	}

	/**
	 * Add an unsampled configuration/invalidation event, never a per-hit DB write.
	 *
	 * @param string $event Fixed event enum.
	 * @param string $reason Fixed reason enum.
	 * @return bool
	 */
	public function log( string $event, string $reason ): bool {
		return true === $this->operate( fn( array $state ): array => $this->append( $state, in_array( $event, array( 'clear', 'error', 'reset' ), true ) ? $event : 'error', self::reason( $reason ) ) );
	}

	/**
	 * Best-effort configuration events cannot change cache-clear success/failure.
	 *
	 * @param string $event Fixed event enum.
	 * @param string $reason Fixed reason enum.
	 */
	public static function event( string $event, string $reason ): void {
		try {
			self::from_wordpress()->log( $event, $reason );
		} catch ( \Throwable $error ) {
			return;
		}
	}

	/** Read an on-demand bounded snapshot; a busy/corrupt store is unavailable. */
	public function snapshot(): array {
		$state = $this->operate();
		return is_array( $state ) ? array(
			'available'      => true,
			'estimated'      => true,
			'current_period' => $this->period,
			'data'           => $state,
		) : array(
			'available' => false,
			'reason'    => 'metrics_unavailable',
		);
	}

	/** Reset counters and diagnostic retention without invalidating HTML. */
	public function reset(): bool {
		return true === $this->operate( fn(): array => $this->append( $this->fresh(), 'reset', 'metrics_reset' ), true );
	}

	/** Remove only this site's marked private files; no directory adoption or link traversal. */
	public function remove(): bool {
		$directory = $this->directory();
		if ( ! file_exists( $directory ) && ! is_link( $directory ) ) {
			return true;
		}
		$lock = false;
		try {
			if ( ! $this->initialize() ) {
				return false;
			}
			$path = $directory . '/.lock';
			$lock = @fopen( $path, 'rb' );
			if ( false === $lock || ! $this->safe_file( $path ) || ! @flock( $lock, LOCK_EX | LOCK_NB ) ) {
				return false;
			}
			$opened = @fstat( $lock );
			$named  = @lstat( $path );
			if ( ! is_array( $opened ) || ( $named['ino'] ?? null ) !== $opened['ino'] || ( $named['dev'] ?? null ) !== $opened['dev'] ) {
				return false;
			}
			$files    = array();
			$complete = true;
			foreach ( new \FilesystemIterator( $directory ) as $item ) {
				if ( count( $files ) >= 1000 ) {
					$complete = false;
					break;
				}
				$name = $item->getFilename();
				if ( ( ! in_array( $name, array( '.owner', '.lock', 'state.json' ), true ) && ! preg_match( '/\A\.tmp-[a-f0-9]{16}\z/D', $name ) ) || ! $this->safe_file( $item->getPathname() ) ) {
					return false;
				}
				if ( ! in_array( $name, array( '.owner', '.lock' ), true ) ) {
					$files[] = $item->getPathname();
				}
			}
			foreach ( $complete ? array_merge( $files, array( $directory . '/.owner', $path ) ) : $files as $file ) {
				if ( ! $this->safe_file( $file ) || ! @unlink( $file ) ) {
					return false;
				}
			}
			return $complete && @rmdir( $directory );
		} catch ( \Throwable $error ) {
			return false;
		} finally {
			if ( is_resource( $lock ) ) {
				@flock( $lock, LOCK_UN );
				fclose( $lock );
			}
		}
	}

	/**
	 * Reclaim abandoned private atomic-write temporaries with a bounded sweep.
	 *
	 * @param int $budget Maximum directory entries inspected.
	 * @return int Removed files; unknown or linked data is never removed.
	 */
	public function cleanup( int $budget = 100 ): int {
		$removed = 0;
		$this->operate(
			function ( array $state ) use ( $budget, &$removed ): array {
				$remaining = max( 1, min( 1000, $budget ) );
				foreach ( new \FilesystemIterator( $this->directory() ) as $file ) {
					if ( --$remaining < 0 ) {
						break;
					}
					if ( preg_match( '/\A\.tmp-[a-f0-9]{16}\z/D', $file->getFilename() ) && $this->safe_file( $file->getPathname() ) && $file->getMTime() < ( $this->clock )() - 3600 && @unlink( $file->getPathname() ) ) {
						++$removed;
					}
				}
				return $state;
			}
		);
		return $removed;
	}

	/**
	 * Refuse arbitrary reason values even when called directly by another plugin.
	 *
	 * @param string $reason Candidate enum.
	 * @return string
	 */
	private static function reason( string $reason ): string {
		return in_array( $reason, self::REASONS, true ) ? $reason : 'other';
	}

	/**
	 * Append only a bounded fixed-shape UTC event.
	 *
	 * @param array  $state Valid state.
	 * @param string $event Fixed event.
	 * @param string $reason Fixed reason.
	 * @return array
	 */
	private function append( array $state, string $event, string $reason ): array {
		$state['log'][] = array(
			'time'   => (int) ( $this->clock )(),
			'event'  => $event,
			'reason' => $reason,
		);
		$state['log']   = array_slice( $state['log'], -50 );
		return $state;
	}

	/** Empty estimates begin at a truthful new UTC epoch. */
	private function fresh(): array {
		return array(
			'schema'  => 1,
			'since'   => (int) ( $this->clock )(),
			'period'  => $this->period,
			'samples' => 0,
			'counts'  => array_fill_keys( self::COUNTERS, 0 ),
			'reasons' => array(),
			'log'     => array(),
		);
	}

	/**
	 * Validate the complete bounded schema before exposing any persisted values.
	 *
	 * @param mixed $state Decoded JSON.
	 * @return bool
	 */
	private function valid_state( $state ): bool {
		if ( ! is_array( $state ) || array_keys( $state ) !== array( 'schema', 'since', 'period', 'samples', 'counts', 'reasons', 'log' ) || 1 !== $state['schema'] || ! is_int( $state['since'] ) || $state['since'] < 0 || ! is_int( $state['period'] ) || $state['period'] < 0 || $state['period'] > 10000 || ! is_int( $state['samples'] ) || $state['samples'] < 0 || $state['samples'] > self::LIMIT || ! is_array( $state['counts'] ) || array_keys( $state['counts'] ) !== self::COUNTERS || ! is_array( $state['reasons'] ) || count( $state['reasons'] ) > count( self::REASONS ) || ! is_array( $state['log'] ) || count( $state['log'] ) > 50 ) {
			return false;
		}
		foreach ( array_merge( $state['counts'], $state['reasons'] ) as $count ) {
			if ( ! is_int( $count ) || $count < 0 || $count > self::LIMIT ) {
				return false;
			}
		}
		foreach ( array_keys( $state['reasons'] ) as $reason ) {
			if ( ! in_array( $reason, self::REASONS, true ) ) {
				return false;
			}
		}
		foreach ( $state['log'] as $entry ) {
			if ( ! is_array( $entry ) || array_keys( $entry ) !== array( 'time', 'event', 'reason' ) || ! is_int( $entry['time'] ) || $entry['time'] < 0 || ! in_array( $entry['event'], array( 'clear', 'error', 'reset' ), true ) || ! in_array( $entry['reason'], self::REASONS, true ) ) {
				return false;
			}
		}
		return true;
	}

	/** Owned private folder, never a public URL or a caller-controlled filename. */
	private function directory(): string {
		return $this->base . '/owc-metrics-' . $this->site;
	}

	/** Verify canonical containment and 0700 privacy, without scanning cache trees. */
	private function safe_directory(): bool {
		$path = $this->directory();
		clearstatcache( true, $path );
		return realpath( $this->base ) === $this->base && realpath( $path ) === $path && ! is_link( $path ) && is_dir( $path ) && 0700 === ( @fileperms( $path ) & 0777 );
	}

	/**
	 * A private file must be singly linked, regular, mode 0600 and directory-owned.
	 *
	 * @param string $path Fixed internal path.
	 * @return bool
	 */
	private function safe_file( string $path ): bool {
		clearstatcache( true, $path );
		$stat  = @lstat( $path );
		$owner = @fileowner( $this->directory() );
		return $this->safe_directory() && ! is_link( $path ) && is_file( $path ) && is_array( $stat ) && 1 === $stat['nlink'] && 0600 === ( $stat['mode'] & 0777 ) && $stat['uid'] === $owner;
	}

	/**
	 * Bounded descriptor-verified private read.
	 *
	 * @param string $path Fixed internal path.
	 * @param int    $limit Read cap.
	 * @return string|null
	 */
	private function read( string $path, int $limit ): ?string {
		if ( ! $this->safe_file( $path ) ) {
			return null;
		}
		$file = @fopen( $path, 'rb' );
		if ( false === $file ) {
			return null;
		}
		try {
			$opened = @fstat( $file );
			$named  = @lstat( $path );
			if ( ! is_array( $opened ) || ! $this->safe_file( $path ) || ( $named['ino'] ?? null ) !== $opened['ino'] || ( $named['dev'] ?? null ) !== $opened['dev'] ) {
				return null;
			}
			$bytes = @stream_get_contents( $file, $limit + 1 );
			return is_string( $bytes ) && strlen( $bytes ) <= $limit ? $bytes : null;
		} finally {
			fclose( $file );
		}
	}

	/** Initialize only an empty private directory; never adopt foreign contents. */
	private function initialize(): bool {
		if ( ! preg_match( '/\A[a-f0-9]{64}\z/D', $this->site ) || realpath( $this->base ) !== $this->base ) {
			return false;
		}
		$directory = $this->directory();
		foreach ( $this->public_roots as $root ) {
			if ( ! is_string( $root ) || '' === $root ) {
				continue;
			}
			$root = rtrim( realpath( $root ) !== false ? realpath( $root ) : $root, '/\\' );
			if ( $directory === $root || str_starts_with( $directory, $root . '/' ) ) {
				return false;
			}
		}
		if ( ! file_exists( $directory ) && ! is_link( $directory ) && ! @mkdir( $directory, 0700 ) ) {
			return false;
		}
		if ( ! $this->safe_directory() || ! is_writable( $directory ) ) {
			return false;
		}
		$marker = $directory . '/.owner';
		$owner  = "Open WP Cache private metrics v1\n" . $this->site . "\n";
		if ( ! file_exists( $marker ) && ! is_link( $marker ) ) {
			$items = new \FilesystemIterator( $directory );
			if ( $items->valid() || ! $this->create( $marker, $owner ) ) {
				return false;
			}
		}
		if ( $this->read( $marker, 256 ) !== $owner ) {
			return false;
		}
		$lock = $directory . '/.lock';
		return ( file_exists( $lock ) || is_link( $lock ) || $this->create( $lock, '' ) ) && $this->safe_file( $lock );
	}

	/**
	 * Create a private exclusive file; directory privacy exists before any bytes.
	 *
	 * @param string $path Owned fixed/random path.
	 * @param string $bytes Public-safe fixed schema bytes.
	 * @return bool
	 */
	private function create( string $path, string $bytes ): bool {
		if ( ! $this->safe_directory() ) {
			return false;
		}
		$file = @fopen( $path, 'x+b' );
		if ( false === $file ) {
			return false;
		}
		try {
			return @chmod( $path, 0600 ) && $this->safe_file( $path ) && strlen( $bytes ) === @fwrite( $file, $bytes ) && @fflush( $file );
		} finally {
			fclose( $file );
		}
	}

	/**
	 * One nonblocking lock and bounded atomic read/modify/write; no DB operations.
	 *
	 * @param callable|null $mutation Null reads a snapshot.
	 * @param bool          $reset Explicit reset may replace corrupt owned JSON.
	 * @return array|bool
	 */
	private function operate( ?callable $mutation = null, bool $reset = false ) {
		$lock = false;
		$temp = null;
		try {
			if ( ! $this->initialize() ) {
				return false;
			}
			$lock_path = $this->directory() . '/.lock';
			$lock      = @fopen( $lock_path, 'rb' );
			if ( false === $lock || ! $this->safe_file( $lock_path ) || ! @flock( $lock, ( null === $mutation ? LOCK_SH : LOCK_EX ) | LOCK_NB ) ) {
				return false;
			}
			$opened = @fstat( $lock );
			$named  = @lstat( $lock_path );
			if ( ! is_array( $opened ) || ( $named['ino'] ?? null ) !== $opened['ino'] || ( $named['dev'] ?? null ) !== $opened['dev'] ) {
				return false;
			}
			$path  = $this->directory() . '/state.json';
			$state = ! file_exists( $path ) && ! is_link( $path ) ? $this->fresh() : json_decode( $this->read( $path, self::MAX_BYTES ) ?? '', true );
			if ( $reset && ( ! file_exists( $path ) || $this->safe_file( $path ) ) ) {
				$state = $this->fresh();
			}
			if ( ! $this->valid_state( $state ) ) {
				return false;
			}
			if ( null === $mutation ) {
				return $state;
			}
			$state = $mutation( $state );
			$bytes = json_encode( $state );
			if ( ! $this->valid_state( $state ) || ! is_string( $bytes ) || strlen( $bytes ) > self::MAX_BYTES || ( ( file_exists( $path ) || is_link( $path ) ) && ! $this->safe_file( $path ) ) ) {
				return false;
			}
			$temp = $this->directory() . '/.tmp-' . bin2hex( random_bytes( 8 ) );
			return $this->create( $temp, $bytes ) && $this->safe_directory() && @rename( $temp, $path );
		} catch ( \Throwable $error ) {
			return false;
		} finally {
			if ( null !== $temp && $this->safe_file( $temp ) ) {
				@unlink( $temp );
			}
			if ( is_resource( $lock ) ) {
				@flock( $lock, LOCK_UN );
				fclose( $lock );
			}
		}
	}
}

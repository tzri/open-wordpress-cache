<?php
/**
 * Local, ownership-checked storage with generation and entry locks.
 *
 * PHP filesystem primitives are intentional: WP_Filesystem can use remote
 * transports and cannot provide the required local flock/rename semantics.
 * Expected filesystem errors must fail open without leaking paths into HTML.
 *
 * @package OpenWPCache
 */

// phpcs:disable WordPress.WP.AlternativeFunctions, WordPress.PHP.NoSilencedErrors

namespace OpenWPCache;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** One site's public data only; no executable or serialized PHP is stored. */
final class Storage {

	const MAX_ENTRY = 2097152;
	const MAX_SITE  = 52428800;
	const OWNER     = "Open WP Cache storage v1\n";

	/**
	 * Canonical trusted upload base.
	 *
	 * @var string
	 */
	private string $base = '';
	/**
	 * Site directory.
	 *
	 * @var string
	 */
	private string $directory = '';
	/**
	 * Fixed diagnostic code.
	 *
	 * @var string
	 */
	private string $error_code = '';
	/**
	 * Initialization succeeded.
	 *
	 * @var bool
	 */
	private bool $ready = false;
	/**
	 * Creation second of this instance's last successful publication.
	 *
	 * @var int|null
	 */
	private ?int $published_at = null;
	/**
	 * Unix time provider.
	 *
	 * @var \Closure
	 */
	private \Closure $clock;

	/**
	 * Resolve the actual WordPress upload base and stable per-install/blog identity.
	 *
	 * @return self
	 */
	public static function from_wordpress(): self {
		$uploads = wp_upload_dir( null, false );
		$base    = empty( $uploads['error'] ) && is_string( $uploads['basedir'] ?? null ) ? $uploads['basedir'] : '';
		$site    = Cache_Key::site( get_current_blog_id(), ABSPATH );
		try {
			return new self( $base, $site );
		} catch ( \Throwable $error ) {
			$storage             = new self( '', $site );
			$storage->error_code = 'storage_io';
			return $storage;
		}
	}

	/**
	 * Initialize owned storage. A foreign or unhealthy directory is never adopted.
	 *
	 * @param string        $base Local uploads basedir.
	 * @param string        $site Opaque site identity.
	 * @param callable|null $clock Optional clock for deterministic expiry testing.
	 */
	public function __construct( string $base, string $site, ?callable $clock = null ) {
		$this->clock = \Closure::fromCallable( $clock ?? 'time' );
		$base        = rtrim( $base, '/\\' );
		if ( '' === $base || str_contains( $base, '://' ) || str_contains( $base, "\0" ) || is_link( $base ) || ! preg_match( '/\A[a-f0-9]{64}\z/D', $site ) ) {
			$this->fail( 'storage_not_local' );
			return;
		}
		$canonical = realpath( $base );
		if ( false === $canonical || ! is_dir( $canonical ) || ! is_writable( $canonical ) ) {
			$this->fail( 'storage_unwritable' );
			return;
		}
		$this->base = $canonical;
		$root       = $canonical . '/open-wp-cache';
		if ( ! $this->owned_directory( $root ) || ! $this->owned_directory( $root . '/site-' . $site ) ) {
			return;
		}
		$this->directory = $root . '/site-' . $site;
		$initialize      = ! file_exists( $this->directory . '/.current' );
		$lock            = $this->lock( $this->directory . '/.lock', $initialize ? LOCK_EX : LOCK_SH, $initialize );
		if ( false === $lock ) {
			return;
		}
		try {
			if ( ! file_exists( $this->directory . '/.current' ) ) {
				$this->ready = $this->rotate();
			} else {
				$this->ready = null !== $this->current();
			}
		} finally {
			$this->unlock( $lock );
		}
	}

	/**
	 * Return the last fixed diagnostic code, without filesystem paths.
	 *
	 * @return string
	 */
	public function error(): string {
		return $this->error_code;
	}

	/**
	 * Whether local storage was initialized successfully.
	 *
	 * @return bool
	 */
	public function operational(): bool {
		return $this->ready;
	}

	/**
	 * Snapshot the generation before rendering.
	 *
	 * @return string|null
	 */
	public function generation(): ?string {
		$lock = $this->ready ? $this->lock( $this->directory . '/.lock', LOCK_SH ) : false;
		if ( false === $lock ) {
			return null;
		}
		try {
			return $this->current();
		} finally {
			$this->unlock( $lock );
		}
	}

	/**
	 * Read a coherent, fresh entry under shared global/entry locks.
	 *
	 * @param string $key Opaque entry key.
	 * @param string $generation Captured generation.
	 * @return array|null Body and safe metadata, or a miss.
	 */
	public function read( string $key, string $generation ): ?array {
		if ( ! $this->ready || ! $this->valid_identity( $key, $generation ) ) {
			return null;
		}
		$global = $this->lock( $this->directory . '/.lock', LOCK_SH );
		if ( false === $global ) {
			return null;
		}
		$entry_lock = false;
		try {
			if ( $this->current() !== $generation ) {
				return null;
			}
			$path = $this->directory . '/g-' . $generation . '/' . $key;
			if ( ! $this->safe_file( $path . '.html' ) || ! $this->safe_file( $path . '.json' ) ) {
				return null;
			}
			$entry_lock = $this->lock( $path . '.lock', LOCK_SH );
			if ( false === $entry_lock ) {
				return null;
			}
			$meta = json_decode( $this->read_file( $path . '.json', 2048 ) ?? '', true );
			$body = $this->read_file( $path . '.html', self::MAX_ENTRY );
			if (
				! is_array( $meta ) || 6 !== count( $meta ) || null === $body
				|| 1 !== ( $meta['schema'] ?? null ) || ( $meta['generation'] ?? null ) !== $generation
				|| ! is_int( $meta['created'] ?? null ) || ! is_int( $meta['expires'] ?? null )
				|| 0 > $meta['created'] || $meta['created'] > ( $this->clock )()
				|| $meta['expires'] <= $meta['created']
				|| 604800 < $meta['expires'] - $meta['created']
				|| ( $meta['length'] ?? null ) !== strlen( $body )
				|| ( $meta['digest'] ?? null ) !== hash( 'sha256', $body )
			) {
				$this->fail( 'storage_invalid_entry' );
				return null;
			}
			if ( $meta['expires'] <= ( $this->clock )() ) {
				return null;
			}
			return array(
				'body' => $body,
				'meta' => $meta,
			);
		} finally {
			$this->unlock( $entry_lock );
			$this->unlock( $global );
		}
	}

	/**
	 * Publish only if the pre-render generation is still current.
	 *
	 * @param string $key Entry key.
	 * @param string $generation Pre-render snapshot.
	 * @param string $body Public HTML bytes.
	 * @param int    $ttl Disk lifetime in seconds.
	 * @return bool
	 */
	public function put( string $key, string $generation, string $body, int $ttl ): bool {
		$this->published_at = null;
		if ( ! $this->ready || ! $this->valid_identity( $key, $generation ) || strlen( $body ) > self::MAX_ENTRY || '' === $body || $ttl < 1 || $ttl > 604800 ) {
			return $this->fail( 'storage_invalid_entry' );
		}
		$global = $this->lock( $this->directory . '/.lock', LOCK_SH );
		if ( false === $global ) {
			return false;
		}
		$entry_lock = false;
		$usage_lock = false;
		try {
			if ( $this->current() !== $generation ) {
				return $this->fail( 'storage_generation_changed' );
			}
			$folder     = $this->directory . '/g-' . $generation;
			$path       = $folder . '/' . $key;
			$entry_lock = $this->lock( $path . '.lock', LOCK_EX, true );
			$usage_lock = false !== $entry_lock ? $this->lock( $folder . '/.usage-lock', LOCK_EX, true ) : false;
			if ( false === $usage_lock ) {
				return false;
			}
			$usage = $this->read_file( $folder . '/.usage', 32 );
			if ( null === $usage || ! ctype_digit( trim( $usage ) ) ) {
				return $this->fail( 'storage_invalid_usage' );
			}
			$old_size = $this->safe_file( $path . '.html' ) ? (int) filesize( $path . '.html' ) : 0;
			$size     = max( 0, (int) $usage + strlen( $body ) - $old_size );
			if ( $size > self::MAX_SITE ) {
				return $this->fail( 'storage_full' );
			}
			$created = ( $this->clock )();
			$meta    = array(
				'schema'     => 1,
				'generation' => $generation,
				'created'    => $created,
				'expires'    => $created + $ttl,
				'length'     => strlen( $body ),
				'digest'     => hash( 'sha256', $body ),
			);
			// Reserve before publication: a crash may overcount, never freely overfill.
			$published = $this->atomic_write( $folder . '/.usage', (string) $size )
				&& $this->atomic_write( $path . '.html', $body )
				&& $this->atomic_write( $path . '.json', (string) json_encode( $meta ) );
			if ( $published ) {
				$this->published_at = $created;
			}
			return $published;
		} finally {
			$this->unlock( $usage_lock );
			$this->unlock( $entry_lock );
			$this->unlock( $global );
		}
	}

	/** Get the creation time belonging to this instance's own successful write. */
	public function published_at(): ?int {
		return $this->published_at;
	}

	/**
	 * On-demand bounded physical usage; never invoked by the frontend HIT path.
	 *
	 * Counts HTML files in active versus detached generations, including expired
	 * candidates awaiting cleanup. A partial result is a lower bound, not zero.
	 *
	 * @param int $budget Maximum inspected directory entries.
	 * @return array
	 */
	public function usage( int $budget = 1000 ): array {
		$result = array(
			'available'           => false,
			'partial'             => true,
			'inspected'           => 0,
			'active_bytes'        => 0,
			'obsolete_bytes'      => 0,
			'active_body_files'   => 0,
			'obsolete_body_files' => 0,
			'control_bytes'       => 0,
		);
		$lock   = $this->ready ? $this->lock( $this->directory . '/.lock', LOCK_SH | LOCK_NB ) : false;
		if ( false === $lock ) {
			return $result;
		}
		try {
			$generation = $this->current();
			if ( null === $generation ) {
				return $result;
			}
			$budget              = max( 1, min( 10000, $budget ) );
			$result['available'] = true;
			$result['partial']   = false;
			foreach ( new \FilesystemIterator( $this->directory ) as $item ) {
				if ( $result['inspected'] >= $budget ) {
					$result['partial'] = true;
					break;
				}
				++$result['inspected'];
				$name = $item->getFilename();
				if ( ! preg_match( '/\Ag-[a-f0-9]{32}\z/D', $name ) ) {
					if ( in_array( $name, array( '.owner', '.lock', '.current', 'index.html' ), true ) && $this->safe_file( $item->getPathname() ) ) {
						$result['control_bytes'] += $item->getSize();
					} else {
						$result['partial'] = true;
					}
					continue;
				}
				if ( ! $this->valid_directory( $item->getPathname() ) ) {
					$result['partial'] = true;
					continue;
				}
				$kind = 'g-' . $generation === $name ? 'active' : 'obsolete';
				foreach ( new \FilesystemIterator( $item->getPathname() ) as $file ) {
					if ( $result['inspected'] >= $budget ) {
						$result['partial'] = true;
						break 2;
					}
					++$result['inspected'];
					if ( ! $this->known_file( $file->getFilename() ) || ! $this->safe_file( $file->getPathname() ) ) {
						$result['partial'] = true;
						continue;
					}
					$result[ $kind . '_bytes' ] += $file->getSize();
					if ( preg_match( '/\A[a-f0-9]{64}\.html\z/D', $file->getFilename() ) ) {
						++$result[ $kind . '_body_files' ];
					}
				}
			}
			return $result;
		} catch ( \Throwable $error ) {
			$result['partial'] = true;
			return $result;
		} finally {
			$this->unlock( $lock );
		}
	}

	/**
	 * Rotate under an exclusive global lock; late writers cannot repopulate it.
	 *
	 * @return bool
	 */
	public function clear(): bool {
		$lock = $this->ready ? $this->lock( $this->directory . '/.lock', LOCK_EX ) : false;
		if ( false === $lock ) {
			return false;
		}
		try {
			return $this->rotate();
		} finally {
			$this->unlock( $lock );
		}
	}

	/**
	 * Remove this owned site's files for uninstall, never following links or foreign names.
	 * A bounded refusal leaves ownership markers intact for an explicit retry.
	 *
	 * @param int $budget Maximum entries inspected; no network-wide traversal.
	 * @return bool Whether the selected site directory was removed completely.
	 */
	public function remove( int $budget = 10000 ): bool {
		if ( '' === $this->directory || self::OWNER !== $this->read_file( dirname( $this->directory ) . '/.owner', 128 ) || self::OWNER !== $this->read_file( $this->directory . '/.owner', 128 ) ) {
			return false;
		}
		$lock = $this->lock( $this->directory . '/.lock', LOCK_EX | LOCK_NB );
		if ( false === $lock ) {
			return false;
		}
		try {
			$budget = max( 1, min( 100000, $budget ) );
			foreach ( new \FilesystemIterator( $this->directory ) as $item ) {
				if ( --$budget < 0 ) {
					return false;
				}
				$name = $item->getFilename();
				if ( in_array( $name, array( '.owner', '.lock', '.current', 'index.html' ), true ) ) {
					if ( ! $this->safe_file( $item->getPathname() ) ) {
						return false;
					}
					continue;
				}
				if ( preg_match( '/\A\.tmp-[a-f0-9]{32}\z/D', $name ) && $this->safe_file( $item->getPathname() ) ) {
					if ( ! @unlink( $item->getPathname() ) ) {
						return false;
					}
					continue;
				}
				if ( ! preg_match( '/\Ag-[a-f0-9]{32}\z/D', $name ) || ! $this->valid_directory( $item->getPathname() ) ) {
					return false;
				}
				foreach ( new \FilesystemIterator( $item->getPathname() ) as $file ) {
					if ( --$budget < 0 || ! $this->known_file( $file->getFilename() ) || ! $this->safe_file( $file->getPathname() ) || ! @unlink( $file->getPathname() ) ) {
						return false;
					}
				}
				if ( ! @rmdir( $item->getPathname() ) ) {
					return false;
				}
			}
			foreach ( array( '.current', 'index.html', '.owner', '.lock' ) as $name ) {
				$path = $this->directory . '/' . $name;
				if ( ! $this->safe_file( $path ) || ! @unlink( $path ) ) {
					return false;
				}
			}
			$this->ready = false;
			if ( ! @rmdir( $this->directory ) ) {
				return false;
			}
			// The shared parent may contain another site or a foreign file. Leave those alone.
			$parent = dirname( $this->directory );
			foreach ( new \FilesystemIterator( $parent ) as $item ) {
				if ( ! in_array( $item->getFilename(), array( '.owner', 'index.html' ), true ) || ! $this->safe_file( $item->getPathname() ) ) {
					return true;
				}
			}
			foreach ( array( 'index.html', '.owner' ) as $name ) {
				if ( ! @unlink( $parent . '/' . $name ) ) {
					return true;
				}
			}
			@rmdir( $parent );
			return true;
		} catch ( \Throwable $error ) {
			return false;
		} finally {
			$this->unlock( $lock );
		}
	}

	/**
	 * Remove detached generations only, with a strict inspected-entry budget.
	 *
	 * @param int $budget Maximum filesystem entries inspected per sweep.
	 * @return int Removed entries. A refusal is available through error().
	 */
	public function cleanup( int $budget = 100 ): int {
		if ( ! $this->ready || self::OWNER !== $this->read_file( dirname( $this->directory ) . '/.owner', 128 ) || self::OWNER !== $this->read_file( $this->directory . '/.owner', 128 ) ) {
			$this->fail( 'storage_foreign_directory' );
			return 0;
		}
		$lock = $this->ready ? $this->lock( $this->directory . '/.lock', LOCK_EX ) : false;
		if ( false === $lock ) {
			return 0;
		}
		$removed = 0;
		try {
			$current = $this->current();
			if ( null === $current ) {
				return 0;
			}
			$budget = max( 1, min( 1000, $budget ) );
			$usage  = $this->read_file( $this->directory . '/g-' . $current . '/.usage', 32 );
			if ( null === $usage || ! ctype_digit( trim( $usage ) ) || (int) $usage > self::MAX_SITE - self::MAX_ENTRY ) {
				if ( ! $this->rotate() ) {
					return 0;
				}
				$current = $this->current();
			}
			foreach ( new \FilesystemIterator( $this->directory, \FilesystemIterator::SKIP_DOTS ) as $item ) {
				if ( --$budget < 0 ) {
					break;
				}
				$name = $item->getFilename();
				if ( ! preg_match( '/\Ag-[a-f0-9]{32}\z/D', $name ) ) {
					continue;
				}
				$folder = $this->directory . '/' . $name;
				if ( ! $this->valid_directory( $folder ) ) {
					$this->fail( 'storage_unsafe_path' );
					break;
				}
				if ( 'g-' . $current === $name ) {
					continue;
				}
				foreach ( new \FilesystemIterator( $folder, \FilesystemIterator::SKIP_DOTS ) as $file ) {
					if ( --$budget < 0 ) {
						break 2;
					}
					$filename = $file->getFilename();
					if ( ! preg_match( '/\A(?:[a-f0-9]{64}\.(?:html|json|lock)|\.tmp-[a-f0-9]{32}|\.usage(?:-lock)?|index\.html)\z/D', $filename ) || ! $this->safe_file( $file->getPathname() ) ) {
						$this->fail( 'storage_unsafe_path' );
						break 2;
					}
					if ( @unlink( $file->getPathname() ) ) {
						++$removed;
					}
				}
				if ( @rmdir( $folder ) ) {
					++$removed;
				}
			}
			if ( 0 < $budget ) {
				$removed += $this->cleanup_active( $this->directory . '/g-' . $current, $budget );
			}
		} catch ( \Throwable $error ) {
			$this->fail( 'storage_io' );
		} finally {
			$this->unlock( $lock );
		}
		return $removed;
	}

	/**
	 * Expire active entries and abandoned temporary files under the global EX lock.
	 *
	 * @param string $folder Current generation directory.
	 * @param int    $budget Remaining inspection budget, shared with the sweep.
	 * @return int Deleted entries.
	 */
	private function cleanup_active( string $folder, int &$budget ): int {
		$removed = 0;
		$usage   = (int) ( $this->read_file( $folder . '/.usage', 32 ) ?? '0' );
		foreach ( new \FilesystemIterator( $folder, \FilesystemIterator::SKIP_DOTS ) as $file ) {
			if ( --$budget < 0 ) {
				break;
			}
			$name = $file->getFilename();
			if ( ! preg_match( '/\A(?:[a-f0-9]{64}\.(?:html|json|lock)|\.tmp-[a-f0-9]{32}|\.usage(?:-lock)?|index\.html)\z/D', $name ) || ! $this->safe_file( $file->getPathname() ) ) {
				$this->fail( 'storage_unsafe_path' );
				break;
			}
			if ( preg_match( '/\A\.tmp-[a-f0-9]{32}\z/D', $name ) && $file->getMTime() < ( $this->clock )() - 3600 ) {
				$removed += @unlink( $file->getPathname() ) ? 1 : 0;
			}
			if ( ! preg_match( '/\A[a-f0-9]{64}\.json\z/D', $name ) ) {
				continue;
			}
			$meta = json_decode( $this->read_file( $file->getPathname(), 2048 ) ?? '', true );
			if ( is_array( $meta ) && is_int( $meta['expires'] ?? null ) && $meta['expires'] > ( $this->clock )() ) {
				continue;
			}
			if ( $budget < 2 ) {
				break;
			}
			$budget -= 2;
			$path    = $folder . '/' . substr( $name, 0, 64 );
			foreach ( array( '.html', '.json', '.lock' ) as $extension ) {
				if ( ! file_exists( $path . $extension ) && ! is_link( $path . $extension ) ) {
					continue;
				}
				if ( ! $this->safe_file( $path . $extension ) ) {
					$this->fail( 'storage_unsafe_path' );
					break 2;
				}
				$bytes = '.html' === $extension ? filesize( $path . $extension ) : 0;
				if ( @unlink( $path . $extension ) ) {
					++$removed;
					$usage = max( 0, $usage - $bytes );
				}
			}
		}
		$this->atomic_write( $folder . '/.usage', (string) $usage );
		return $removed;
	}

	/**
	 * Validate opaque identifiers before any filesystem access.
	 *
	 * @param string $key Entry hash.
	 * @param string $generation Generation hash.
	 * @return bool
	 */
	private function valid_identity( string $key, string $generation ): bool {
		return 1 === preg_match( '/\A[a-f0-9]{64}\z/D', $key ) && 1 === preg_match( '/\A[a-f0-9]{32}\z/D', $generation );
	}

	/**
	 * Recognize only this schema's public data filenames.
	 *
	 * @param string $name Basename inside an owned generation.
	 * @return bool
	 */
	private function known_file( string $name ): bool {
		return 1 === preg_match( '/\A(?:[a-f0-9]{64}\.(?:html|json|lock)|\.tmp-[a-f0-9]{32}|\.usage(?:-lock)?|index\.html)\z/D', $name );
	}

	/**
	 * Set a fixed diagnostic reason.
	 *
	 * @param string $reason Reason enum.
	 * @return bool Always false.
	 */
	private function fail( string $reason ): bool {
		$this->error_code = $reason;
		return false;
	}

	/**
	 * Check every owned directory component against its canonical path.
	 *
	 * @param string $path Directory path.
	 * @return bool
	 */
	private function valid_directory( string $path ): bool {
		if ( is_link( $this->base ) || realpath( $this->base ) !== $this->base || ! is_dir( $this->base ) ) {
			return false;
		}
		if ( $path !== $this->base && ! str_starts_with( $path, $this->base . '/' ) ) {
			return false;
		}
		$relative = substr( $path, strlen( $this->base ) );
		$check    = $this->base;
		foreach ( array_filter( explode( '/', $relative ) ) as $part ) {
			$check .= '/' . $part;
			if ( is_link( $check ) || ! is_dir( $check ) || realpath( $check ) !== $check ) {
				return false;
			}
		}
		return is_dir( $check );
	}

	/**
	 * Initialize an empty directory or verify its existing ownership marker.
	 *
	 * @param string $path Owned directory.
	 * @return bool
	 */
	private function owned_directory( string $path ): bool {
		if ( ! $this->valid_directory( dirname( $path ) ) || ( ! file_exists( $path ) && ! @mkdir( $path, 0755 ) && ! is_dir( $path ) ) || ! $this->valid_directory( $path ) || ! is_readable( $path ) || ! is_writable( $path ) ) {
			return $this->fail( 'storage_unsafe_path' );
		}
		if ( file_exists( $path . '/.owner' ) || is_link( $path . '/.owner' ) ) {
			return self::OWNER === $this->read_file( $path . '/.owner', 128 ) || $this->fail( 'storage_foreign_directory' );
		}
		if ( ( new \FilesystemIterator( $path, \FilesystemIterator::SKIP_DOTS ) )->valid() ) {
			return $this->fail( 'storage_foreign_directory' );
		}
		return $this->atomic_write( $path . '/.owner', self::OWNER ) && $this->atomic_write( $path . '/index.html', '' );
	}

	/**
	 * A regular, singly-linked file in a verified directory.
	 *
	 * @param string $path File path.
	 * @return bool
	 */
	private function safe_file( string $path ): bool {
		clearstatcache( true, $path );
		$stat = @lstat( $path );
		return $this->valid_directory( dirname( $path ) ) && ! is_link( $path ) && is_file( $path ) && is_array( $stat ) && 1 === $stat['nlink'];
	}

	/**
	 * Bounded read of a verified file.
	 *
	 * @param string $path File path.
	 * @param int    $limit Maximum bytes.
	 * @return string|null
	 */
	private function read_file( string $path, int $limit ): ?string {
		if ( ! $this->safe_file( $path ) ) {
			return null;
		}
		$size = @filesize( $path );
		if ( false === $size || $size > $limit ) {
			return null;
		}
		$stream = @fopen( $path, 'rb' );
		if ( false === $stream ) {
			return null;
		}
		try {
			$opened = @fstat( $stream );
			$named  = @lstat( $path );
			if ( ! is_array( $opened ) || ! $this->safe_file( $path ) || ( $named['ino'] ?? null ) !== $opened['ino'] || ( $named['dev'] ?? null ) !== $opened['dev'] ) {
				return null;
			}
			$body = @stream_get_contents( $stream, $limit + 1 );
			return is_string( $body ) && strlen( $body ) <= $limit ? $body : null;
		} finally {
			fclose( $stream );
		}
	}

	/**
	 * Same-directory exclusive temp creation and atomic replacement.
	 *
	 * @param string $path Destination.
	 * @param string $bytes Data bytes.
	 * @return bool
	 */
	private function atomic_write( string $path, string $bytes ): bool {
		if ( ! $this->valid_directory( dirname( $path ) ) || ( ( file_exists( $path ) || is_link( $path ) ) && ! $this->safe_file( $path ) ) ) {
			return $this->fail( 'storage_unsafe_path' );
		}
		try {
			$temp = dirname( $path ) . '/.tmp-' . bin2hex( random_bytes( 16 ) );
		} catch ( \Throwable $error ) {
			return $this->fail( 'storage_randomness' );
		}
		$stream = @fopen( $temp, 'xb' );
		if ( false === $stream ) {
			return $this->fail( 'storage_io' );
		}
		$written = 0;
		$length  = strlen( $bytes );
		try {
			@chmod( $temp, 0644 );
			while ( $written < $length ) {
				$count = @fwrite( $stream, substr( $bytes, $written ) );
				if ( false === $count || 0 === $count ) {
					return $this->fail( 'storage_io' );
				}
				$written += $count;
			}
			if ( ! @fflush( $stream ) || ! @fsync( $stream ) ) {
				return $this->fail( 'storage_io' );
			}
			fclose( $stream );
			$stream = false;
			if ( ! $this->valid_directory( dirname( $path ) ) || ( is_link( $path ) ) || ! @rename( $temp, $path ) ) {
				return $this->fail( 'storage_io' );
			}
			return true;
		} finally {
			if ( is_resource( $stream ) ) {
				fclose( $stream );
			}
			if ( is_file( $temp ) && ! is_link( $temp ) ) {
				@unlink( $temp );
			}
		}
	}

	/**
	 * Lock a regular file, creating it only in owned paths when requested.
	 *
	 * @param string $path Lock path.
	 * @param int    $mode LOCK_SH or LOCK_EX.
	 * @param bool   $create Whether creation is allowed.
	 * @return resource|false
	 */
	private function lock( string $path, int $mode, bool $create = false ) {
		if ( ! $this->valid_directory( dirname( $path ) ) || ( ! $this->safe_file( $path ) && ( ! $create || file_exists( $path ) || is_link( $path ) ) ) ) {
			$this->fail( 'storage_unsafe_path' );
			return false;
		}
		$stream = @fopen( $path, $create ? 'c+b' : 'rb' );
		if ( false === $stream || ! $this->safe_file( $path ) || ! @flock( $stream, $mode ) ) {
			if ( is_resource( $stream ) ) {
				fclose( $stream );
			}
			$this->fail( 'storage_lock' );
			return false;
		}
		$opened = @fstat( $stream );
		$named  = @lstat( $path );
		if ( ! is_array( $opened ) || ( $named['ino'] ?? null ) !== $opened['ino'] || ( $named['dev'] ?? null ) !== $opened['dev'] ) {
			$this->unlock( $stream );
			$this->fail( 'storage_unsafe_path' );
			return false;
		}
		if ( $create ) {
			@chmod( $path, 0644 );
		}
		return $stream;
	}

	/**
	 * Close only this plugin's lock resource.
	 *
	 * @param resource|false $stream Owned lock.
	 */
	private function unlock( $stream ): void {
		if ( is_resource( $stream ) ) {
			flock( $stream, LOCK_UN );
			fclose( $stream );
		}
	}

	/**
	 * Read the active pointer only while the caller holds the global lock.
	 *
	 * @return string|null
	 */
	private function current(): ?string {
		$generation = trim( $this->read_file( $this->directory . '/.current', 64 ) ?? '' );
		if ( ! preg_match( '/\A[a-f0-9]{32}\z/D', $generation ) || ! $this->valid_directory( $this->directory . '/g-' . $generation ) ) {
			$this->fail( 'storage_invalid_generation' );
			return null;
		}
		if ( ! is_readable( $this->directory . '/g-' . $generation ) || ! is_writable( $this->directory . '/g-' . $generation ) ) {
			$this->fail( 'storage_unwritable' );
			return null;
		}
		return $generation;
	}

	/**
	 * Create and select a new empty generation while holding the global EX lock.
	 *
	 * @return bool
	 */
	private function rotate(): bool {
		try {
			$generation = bin2hex( random_bytes( 16 ) );
		} catch ( \Throwable $error ) {
			return $this->fail( 'storage_randomness' );
		}
		$folder = $this->directory . '/g-' . $generation;
		return $this->valid_directory( $this->directory ) && @mkdir( $folder, 0755 )
			&& $this->atomic_write( $folder . '/index.html', '' )
			&& $this->atomic_write( $folder . '/.usage', '0' )
			&& $this->atomic_write( $this->directory . '/.current', $generation );
	}
}

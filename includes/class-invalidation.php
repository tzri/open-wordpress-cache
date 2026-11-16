<?php
/**
 * Immediate per-site invalidation and shared manual-clear service.
 *
 * @package OpenWPCache
 */

namespace OpenWPCache;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Whole-site generations cover all old/new content dependencies. */
final class Invalidation {
	public const LAST_OPTION  = 'open_wp_cache_last_clear';
	public const ERROR_OPTION = 'open_wp_cache_invalidation_error';

	/**
	 * Query counter after each automatic purge, isolated by blog.
	 *
	 * @var array
	 */
	private static array $queries = array();

	/** Register post-mutation hooks, never a deferred freshness task. */
	public static function register(): void {
		foreach ( array( 'wp_after_insert_post', 'deleted_post' ) as $hook ) {
			add_action( $hook, array( self::class, 'post_event' ), 10, 2 );
		}
		add_action( 'transition_post_status', array( self::class, 'status_event' ), 10, 3 );
		foreach ( array( 'added_post_meta', 'updated_post_meta', 'deleted_post_meta' ) as $hook ) {
			add_action( $hook, array( self::class, 'post_meta' ), 10, 3 );
		}
		foreach ( array( 'wp_insert_comment', 'edit_comment', 'transition_comment_status', 'deleted_comment' ) as $hook ) {
			add_action( $hook, array( self::class, 'comment_event' ), 10, 0 );
		}
		foreach ( array( 'created_term', 'edited_term', 'delete_term', 'set_object_terms', 'deleted_term_relationships', 'added_term_meta', 'updated_term_meta', 'deleted_term_meta' ) as $hook ) {
			add_action( $hook, array( self::class, 'taxonomy_event' ), 10, 0 );
		}
		foreach ( array( 'switch_theme', 'wp_update_nav_menu', 'wp_delete_nav_menu', 'customize_save_after' ) as $hook ) {
			add_action( $hook, array( self::class, 'appearance_event' ), 10, 0 );
		}
		foreach ( array( 'added_option', 'updated_option', 'deleted_option' ) as $hook ) {
			add_action( $hook, array( self::class, 'option_event' ), 10, 1 );
		}
	}

	/**
	 * Invalidate real posts; autosave/revision objects cannot change public output.
	 *
	 * @param int      $post_id Changed post ID.
	 * @param \WP_Post $post Changed/deleted post object.
	 */
	public static function post_event( int $post_id, \WP_Post $post ): void {
		if ( 'revision' !== $post->post_type ) {
			self::automatic( 'post' );
		}
	}

	/**
	 * Include scheduled publication and other direct status APIs.
	 *
	 * @param string   $new_status New status.
	 * @param string   $old Previous status.
	 * @param \WP_Post $post Updated post.
	 */
	public static function status_event( string $new_status, string $old, \WP_Post $post ): void {
		if ( $new_status !== $old ) {
			self::post_event( $post->ID, $post );
		}
	}

	/**
	 * Catch custom fields and thumbnail changes without editor heartbeat churn.
	 *
	 * @param mixed  $meta_id Changed metadata ID(s).
	 * @param int    $post_id Parent post.
	 * @param string $key Metadata key.
	 */
	public static function post_meta( $meta_id, int $post_id, string $key ): void {
		if ( in_array( $key, array( '_edit_lock', '_edit_last' ), true ) || wp_is_post_revision( $post_id ) ) {
			return;
		}
		self::automatic( 'post' );
	}

	/** Comment insertion, edits, moderation and deletion affect public views. */
	public static function comment_event(): void {
		self::automatic( 'comment' );
	}

	/** Term and relationship changes affect both old and new archives. */
	public static function taxonomy_event(): void {
		self::automatic( 'taxonomy' );
	}

	/** Menu/theme/customizer changes affect shared page layout. */
	public static function appearance_event(): void {
		self::automatic( 'appearance' );
	}

	/**
	 * Conservative rendering-option policy with explicit internal exclusions.
	 *
	 * @param string $name Option name, never a visitor value.
	 * @return bool
	 */
	public static function public_option( string $name ): bool {
		if ( Settings::OPTION === $name ) {
			return true;
		}
		return ! str_starts_with( $name, 'open_wp_cache_' )
			&& ! str_starts_with( $name, '_transient_' )
			&& ! str_starts_with( $name, '_site_transient_' )
			&& ! in_array( $name, array( 'cron', 'auto_updater.lock', 'core_updater.lock' ), true );
	}

	/**
	 * Observe persisted option changes, including widget/permalink/cache settings.
	 *
	 * @param string $name Changed option name.
	 */
	public static function option_event( string $name ): void {
		if ( self::public_option( $name ) ) {
			self::automatic( Settings::OPTION === $name ? 'settings' : 'option' );
		}
	}

	/**
	 * Rotate immediately. Physical reclamation is bounded scheduled maintenance.
	 *
	 * @param string $reason Fixed supported reason; unknown callers become manual.
	 * @return array Truthful success, fixed reason and UTC last-clear information.
	 */
	public static function clear( string $reason = 'manual' ): array {
		if ( ! in_array( $reason, array( 'manual', 'cli', 'settings', 'post', 'comment', 'taxonomy', 'appearance', 'option', 'deactivation' ), true ) ) {
			$reason = 'manual';
		}
		try {
			$storage = Storage::from_wordpress();
			if ( ! $storage->operational() || ! $storage->clear() ) {
				$error = $storage->error();
				return self::failed( '' === $error ? 'storage_unavailable' : $error );
			}
			$record = array(
				'utc'        => gmdate( 'Y-m-d\TH:i:s\Z' ),
				'reason'     => $reason,
				'generation' => $storage->generation(),
			);
			$saved  = update_option( self::LAST_OPTION, $record, false );
			delete_option( self::ERROR_OPTION );
			Statistics::event( 'clear', 'cli' === $reason ? 'cli_clear' : $reason );
			if ( ! wp_next_scheduled( 'open_wp_cache_cleanup' ) ) {
				wp_schedule_event( time() + HOUR_IN_SECONDS, 'hourly', 'open_wp_cache_cleanup' );
			}
			return array(
				'success'         => true,
				'reason'          => $reason,
				'cleared_at'      => $record['utc'],
				'metadata_saved'  => $saved,
				'cleanup_pending' => true,
			);
		} catch ( \Throwable $error ) {
			return self::failed( 'storage_unavailable' );
		}
	}

	/**
	 * Persist a fail-closed guard so a failed purge never revives stale entries.
	 *
	 * @param string $reason Fixed storage error enum.
	 * @return array
	 */
	private static function failed( string $reason ): array {
		update_option( self::ERROR_OPTION, $reason, false );
		Statistics::event( 'error', $reason );
		return array(
			'success'    => false,
			'reason'     => $reason,
			'cleared_at' => null,
		);
	}

	/**
	 * Coalesce only while no further WordPress database query has occurred.
	 *
	 * @param string $reason Fixed automatic reason.
	 */
	private static function automatic( string $reason ): void {
		// Deleted configuration means uninstall/incomplete setup: never recreate removed storage.
		if ( false === get_option( Settings::OPTION, false ) || ! in_array( 'open-wp-cache/open-wp-cache.php', (array) get_option( 'active_plugins', array() ), true ) ) {
			return;
		}
		global $wpdb;
		$blog    = get_current_blog_id();
		$queries = $wpdb->num_queries;
		if ( isset( self::$queries[ $blog ] ) && self::$queries[ $blog ] === $queries ) {
			return;
		}
		$result = self::clear( $reason );
		if ( $result['success'] ) {
			self::$queries[ $blog ] = $wpdb->num_queries;
		}
	}
}

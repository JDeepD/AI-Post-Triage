<?php
/**
 * Creates and upgrades the plugin's database table.
 *
 * @package JDeepD\Wire_Desk
 */

declare( strict_types = 1 );

namespace JDeepD\Wire_Desk\Core;

use JDeepD\Wire_Desk\Framework\Contracts\Interfaces\Registrable;

/**
 * Class - Schema
 */
final class Schema implements Registrable {
	/**
	 * Schema version. Bump it whenever get_table_sql() changes.
	 */
	public const VERSION = 2;

	/**
	 * Option holding the installed schema version.
	 */
	public const VERSION_OPTION = 'wire_desk_db_version';

	/**
	 * Table name, without the site prefix.
	 */
	public const TABLE = 'wire_desk_post';

	/**
	 * Transient set after a failed install.
	 */
	public const FAILURE_TRANSIENT = 'wire_desk_db_install_failed';

	/**
	 * Seconds to wait before retrying a failed install.
	 */
	private const FAILURE_BACKOFF = 300;

	/**
	 * {@inheritDoc}
	 */
	public function register_hooks(): void {
		add_action( 'init', [ $this, 'maybe_upgrade' ], 0 );
	}

	/**
	 * The table name for the current site.
	 */
	public static function table_name(): string {
		global $wpdb;

		return $wpdb->prefix . self::TABLE;
	}

	/**
	 * Installs or upgrades the table when the stored schema version is out of date.
	 */
	public function maybe_upgrade(): void {
		if ( wp_installing() || self::is_current() || false !== get_transient( self::FAILURE_TRANSIENT ) ) {
			return;
		}

		self::install();
	}

	/**
	 * Whether the installed schema version matches this version of the plugin.
	 */
	public static function is_current(): bool {
		return self::VERSION === (int) get_option( self::VERSION_OPTION, 0 );
	}

	/**
	 * Creates or upgrades the table, then records the schema version.
	 *
	 * @return bool Whether the table now exists at the current version.
	 */
	public static function install(): bool {
		global $wpdb;

		$lock = 'wire_desk_schema_' . $wpdb->prefix;

		if ( ! self::acquire_lock( $lock ) ) {
			return false;
		}

		try {
			require_once ABSPATH . 'wp-admin/includes/upgrade.php';

			dbDelta( self::get_table_sql() );

			if ( ! self::table_exists() ) {
				set_transient( self::FAILURE_TRANSIENT, $wpdb->last_error ?: 'unknown', self::FAILURE_BACKOFF );
				return false;
			}

			delete_transient( self::FAILURE_TRANSIENT );
			update_option( self::VERSION_OPTION, self::VERSION, true );

			return true;
		} finally {
			self::release_lock( $lock );
		}
	}

	/**
	 * Whether the table exists.
	 */
	public static function table_exists(): bool {
		global $wpdb;

		$suppress = $wpdb->suppress_errors();
		$columns  = $wpdb->get_results( $wpdb->prepare( 'DESCRIBE %i', self::table_name() ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Schema check.
		$wpdb->suppress_errors( $suppress );

		return ! empty( $columns );
	}

	/**
	 * The CREATE TABLE statement for dbDelta().
	 */
	public static function get_table_sql(): string {
		global $wpdb;

		$table           = self::table_name();
		$charset_collate = $wpdb->get_charset_collate();

		return "CREATE TABLE {$table} (
post_id bigint(20) unsigned NOT NULL,
post_date datetime NOT NULL default '0000-00-00 00:00:00',
state tinyint(3) unsigned NOT NULL default 0,
band tinyint(3) unsigned NOT NULL default 0,
score tinyint(3) unsigned NOT NULL default 0,
model varchar(100) NOT NULL default '',
hash binary(16) NULL default NULL,
cost decimal(12,8) NOT NULL default 0,
scored_at datetime NULL default NULL,
error varchar(255) NOT NULL default '',
attempts tinyint(3) unsigned NOT NULL default 0,
details longtext NULL,
PRIMARY KEY  (post_id),
KEY state_date (state,post_date),
KEY state_band_date (state,band,post_date),
KEY post_date (post_date)
) {$charset_collate};";
	}

	/**
	 * Takes a named database lock without waiting.
	 *
	 * @param string $name Lock name.
	 */
	private static function acquire_lock( string $name ): bool {
		global $wpdb;

		return '1' === $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK( %s, 0 )', $name ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Locks can't be cached.
	}

	/**
	 * Releases a named database lock.
	 *
	 * @param string $name Lock name.
	 */
	private static function release_lock( string $name ): void {
		global $wpdb;

		$wpdb->query( $wpdb->prepare( 'SELECT RELEASE_LOCK( %s )', $name ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Locks can't be cached.
	}
}

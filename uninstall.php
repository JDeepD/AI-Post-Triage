<?php
/**
 * This will be executed when the plugin is uninstalled.
 *
 * @package JDeepD\Wire_Desk
 */

declare( strict_types = 1 );

namespace JDeepD\Wire_Desk;

// Only uninstall if called by WordPress.
defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

// We use local constants so this plugin can be uninstalled even if the autoloader is corrupted or missing.
const PLUGIN_PREFIX = 'wire_desk_';

/**
 * Uninstalls the plugin. If multisite, uninstalls from all sites.
 */
function run_uninstaller(): void {
	if ( ! is_multisite() ) {
		uninstall();
		return;
	}

	$site_ids = get_sites(
		[
			'fields' => 'ids',
			'number' => 0,
		]
	) ?: [];

	foreach ( $site_ids as $site_id ) {
		// phpcs:ignore WordPressVIPMinimum.Functions.RestrictedFunctions.switch_to_blog_switch_to_blog -- The state doesn't matter during uninstall.
		if ( ! switch_to_blog( (int) $site_id ) ) {
			continue;
		}

		uninstall();
		restore_current_blog();
	}
}

/**
 * The (site-specific) uninstall function.
 */
function uninstall(): void {
	if ( ! get_option( PLUGIN_PREFIX . 'delete_data_on_uninstall' ) ) {
		return;
	}

	// Add additional uninstall routines here.
	drop_tables();
	delete_options();
	delete_transients();
}

/**
 * Drops the plugin's tables.
 */
function drop_tables(): void {
	global $wpdb;

	$tables = [
		$wpdb->prefix . PLUGIN_PREFIX . 'post', // Created by Core\Schema.
	];

	foreach ( $tables as $table ) {
		$wpdb->query( $wpdb->prepare( 'DROP TABLE IF EXISTS %i', $table ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange -- Removing the plugin's own table.
	}
}

/**
 * Deletes options.
 */
function delete_options(): void {
	$options = [
		// Add more options as needed.
		PLUGIN_PREFIX . 'version', // Set by Main::activate().
		PLUGIN_PREFIX . 'db_version', // Set by Core\Schema.
		PLUGIN_PREFIX . 'delete_data_on_uninstall', // Set by Modules\Settings\Settings.
		PLUGIN_PREFIX . 'settings', // Set by Modules\Settings\Settings.
	];

	foreach ( $options as $option ) {
		delete_option( $option );
	}
}

/**
 * Deletes transients.
 */
function delete_transients(): void {
	/**
	 * Add more transients as needed.
	 *
	 * @var string[] $transients
	 */
	$transients = [
		PLUGIN_PREFIX . 'db_install_failed', // Set by Core\Schema.
	];

	foreach ( $transients as $transient ) {
		delete_transient( $transient );
	}
}

// Run the uninstaller.
run_uninstaller();

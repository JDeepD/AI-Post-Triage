<?php
/**
 * This will be executed when the plugin is uninstalled.
 *
 * @package JDeepD\AI_Post_Triage
 */

declare( strict_types = 1 );

namespace JDeepD\AI_Post_Triage;

// Only uninstall if called by WordPress.
defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

// We use local constants so this plugin can be uninstalled even if the autoloader is corrupted or missing.
const PLUGIN_PREFIX = 'ai_post_triage_';

// The Action Scheduler group of the plugin's jobs, from Jobs\Queue.
const JOB_GROUP = 'ai-post-triage';

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
	cancel_jobs();

	if ( ! get_option( PLUGIN_PREFIX . 'delete_data_on_uninstall' ) ) {
		return;
	}

	// Add additional uninstall routines here.
	drop_tables();
	delete_options();
	delete_transients();
}

/**
 * Cancels the plugin's waiting jobs, including the daily prune.
 */
function cancel_jobs(): void {
	if ( function_exists( 'as_unschedule_all_actions' ) ) {
		as_unschedule_all_actions( '', [], JOB_GROUP );
	}
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
		PLUGIN_PREFIX . 'usage', // Set by Jobs\Usage.
		PLUGIN_PREFIX . 'backfill', // Set by Jobs\Backfill.
		PLUGIN_PREFIX . 'pruned_before', // Set by Jobs\Maintenance.
		PLUGIN_PREFIX . 'openrouter_api_key', // Set by Modules\Settings\Api_Key.
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
		PLUGIN_PREFIX . 'models', // Set by Decisions\Model_Catalogue.
	];

	foreach ( $transients as $transient ) {
		delete_transient( $transient );
	}
}

// Run the uninstaller.
run_uninstaller();

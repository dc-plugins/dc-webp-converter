<?php
/**
 * DC WebP Converter — Uninstall
 *
 * Fired when the plugin is deleted via the WordPress admin.
 * Removes all plugin-specific options and transients from the database.
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	die;
}

// Remove all plugin options
$options = [
	'dc_p2w_settings',
	'dc_p2w_syscheck',
	'dc_p2w_queue',
	'dc_p2w_total',
	'dc_p2w_done',
	'dc_p2w_errors',
	'dc_p2w_log',
	'dc_p2w_cron_status',
];
foreach ( $options as $option ) {
	delete_option( $option );
}

// Remove transients
delete_transient( 'dc_p2w_footer_strategy' );

// Clear object cache group if supported
if ( function_exists( 'wp_cache_flush_group' ) ) {
	wp_cache_flush_group( 'dc_p2w' );
} else {
	wp_cache_delete( 'dc_p2w_footer_strategy', 'dc_p2w' );
}

// Remove scheduled cron event
$timestamp = wp_next_scheduled( 'dc_p2w_cron_batch' );
if ( $timestamp ) {
	wp_unschedule_event( $timestamp, 'dc_p2w_cron_batch' );
}
wp_clear_scheduled_hook( 'dc_p2w_cron_batch' );

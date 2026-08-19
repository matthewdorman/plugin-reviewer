<?php
/**
 * Plugin uninstall cleanup.
 *
 * @package PluginReviewer
 */

namespace PluginReviewer;

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

global $wpdb;

$plugin_reviewer_patterns = array(
	$wpdb->esc_like( '_transient_plugin_reviewer_' ) . '%',
	$wpdb->esc_like( '_transient_timeout_plugin_reviewer_' ) . '%',
);

foreach ( $plugin_reviewer_patterns as $plugin_reviewer_pattern ) {
	// Direct SQL is required because the transient names are dynamic hashes.
	// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
	$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s", $plugin_reviewer_pattern ) );
}

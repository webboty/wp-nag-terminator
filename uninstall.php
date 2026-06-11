<?php
/**
 * Uninstall handler for NAG Terminator.
 *
 * Removes plugin options, user meta, archive, scheduled events, and
 * per-user dismissal lists across all users. Only fires when the
 * plugin is deleted from the Plugins screen (not on simple deactivation).
 *
 * @package WpNagTerminator
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
    exit;
}

global $wpdb;

// 1. Remove global options.
delete_option( 'wp_nag_terminator_global_dismissed' );
delete_option( 'wp_nag_terminator_archive' );
delete_option( 'wp_nag_terminator_settings' );

// 2. Remove per-user meta.
$meta_key = 'wp_nag_terminator_dismissed';
$users    = get_users(
    array(
        'fields'   => 'ID',
        'meta_key' => $meta_key, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
        'number'   => -1,
    )
);
if ( ! is_wp_error( $users ) && is_array( $users ) ) {
    foreach ( $users as $user_id ) {
        delete_user_meta( (int) $user_id, $meta_key );
    }
}

// 3. Clear scheduled event.
$timestamp = wp_next_scheduled( 'wp_nag_terminator_purge_archive' );
if ( $timestamp ) {
    wp_unschedule_event( $timestamp, 'wp_nag_terminator_purge_archive' );
}
wp_clear_scheduled_hook( 'wp_nag_terminator_purge_archive' );

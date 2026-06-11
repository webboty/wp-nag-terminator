<?php
/**
 * Installer: activation / deactivation / cron.
 *
 * @package WpNagTerminator
 */

namespace WpNagTerminator;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Installer {

    const OPTION_SETTINGS    = 'wp_nag_terminator_settings';
    const CRON_HOOK          = 'wp_nag_terminator_purge_archive';
    const DEFAULT_RETENTION  = 365; // days

    /**
     * Activation: set defaults + schedule cron.
     */
    public static function activate() {
        $existing = get_option( self::OPTION_SETTINGS, array() );
        $defaults = array(
            'retention_days'         => self::DEFAULT_RETENTION,
            'bypass_global_roles'    => array(),
            'action_link_visibility' => 'always', // 'always' | 'hover'
        );

        if ( ! is_array( $existing ) ) {
            $existing = array();
        }

        update_option( self::OPTION_SETTINGS, array_merge( $defaults, $existing ) );

        if ( ! wp_next_scheduled( self::CRON_HOOK ) ) {
            wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', self::CRON_HOOK );
        }
    }

    /**
     * Deactivation: clear cron. Keep data.
     */
    public static function deactivate() {
        $timestamp = wp_next_scheduled( self::CRON_HOOK );
        if ( $timestamp ) {
            wp_unschedule_event( $timestamp, self::CRON_HOOK );
        }
        wp_clear_scheduled_hook( self::CRON_HOOK );
    }

    /**
     * Get settings with defaults applied.
     *
     * @return array
     */
    public static function get_settings() {
        $defaults = array(
            'retention_days'         => self::DEFAULT_RETENTION,
            'bypass_global_roles'    => array(),
            'action_link_visibility' => 'always',
        );
        $stored = get_option( self::OPTION_SETTINGS, array() );
        if ( ! is_array( $stored ) ) {
            $stored = array();
        }
        return array_merge( $defaults, $stored );
    }

    /**
     * Update a single setting key.
     *
     * @param string $key   Setting key.
     * @param mixed  $value Setting value.
     * @return bool
     */
    public static function update_setting( $key, $value ) {
        $settings = self::get_settings();
        $allowed  = array( 'retention_days', 'bypass_global_roles', 'action_link_visibility' );
        if ( ! in_array( $key, $allowed, true ) ) {
            return false;
        }
        $settings[ $key ] = $value;
        return update_option( self::OPTION_SETTINGS, $settings );
    }

    /**
     * Cron callback: purge old archive entries based on retention.
     */
    public static function purge_archive() {
        $settings = self::get_settings();
        $days     = (int) $settings['retention_days'];
        if ( $days <= 0 ) {
            return; // 0 = forever
        }
        Storage::purge_older_than( $days * DAY_IN_SECONDS );
    }
}

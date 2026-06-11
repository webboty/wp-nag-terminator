<?php
/**
 * Capability checks.
 *
 * @package WpNagTerminator
 */

namespace WpNagTerminator;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Capabilities {

    /**
     * Can the current user dismiss a NAG for themselves?
     *
     * @param int|null $user_id User ID. Defaults to current.
     * @return bool
     */
    public static function can_dismiss_for_user( $user_id = null ) {
        if ( null === $user_id ) {
            return is_user_logged_in();
        }
        return user_can( $user_id, 'read' );
    }

    /**
     * Can the current user dismiss a NAG for everyone (global)?
     *
     * @return bool
     */
    public static function can_dismiss_global() {
        return current_user_can( 'manage_options' );
    }

    /**
     * Can the current user restore a globally-dismissed NAG?
     *
     * @return bool
     */
    public static function can_restore_global() {
        return current_user_can( 'manage_options' );
    }

    /**
     * Can the current user permanently delete an archived NAG?
     *
     * @return bool
     */
    public static function can_delete_archive() {
        return current_user_can( 'manage_options' );
    }

    /**
     * Can the current user manage plugin settings?
     *
     * @return bool
     */
    public static function can_manage_settings() {
        return current_user_can( 'manage_options' );
    }

    /**
     * Should this user bypass global terminations?
     *
     * Some users (e.g., site owners) may want to keep seeing NAGs
     * even when "Terminate for everyone" was used.
     *
     * @param int|null $user_id User ID. Defaults to current.
     * @return bool
     */
    public static function should_bypass_global( $user_id = null ) {
        if ( null === $user_id ) {
            $user = wp_get_current_user();
            $user_id = $user ? (int) $user->ID : 0;
        }
        if ( ! $user_id ) {
            return false;
        }
        $settings = Installer::get_settings();
        $bypass   = isset( $settings['bypass_global_roles'] ) && is_array( $settings['bypass_global_roles'] )
            ? $settings['bypass_global_roles']
            : array();

        if ( empty( $bypass ) ) {
            return false;
        }

        $user = get_userdata( $user_id );
        if ( ! $user || empty( $user->roles ) ) {
            return false;
        }
        return (bool) array_intersect( (array) $user->roles, $bypass );
    }

    /**
     * Is the current user a staff-tier user (admin, editor, author,
     * shop manager, or any custom role that can edit posts)?
     *
     * This excludes subscribers, customers, and other roles that
     * shouldn't see the admin bar NAG counter.
     *
     * @param int|null $user_id User ID. Defaults to current.
     * @return bool
     */
    public static function is_staff( $user_id = null ) {
        if ( null === $user_id ) {
            return current_user_can( 'edit_posts' );
        }
        return user_can( (int) $user_id, 'edit_posts' );
    }

    /**
     * Is debug logging enabled? Default off. When on, the plugin
     * may write to the PHP error log to help diagnose issues.
     *
     * @return bool
     */
    public static function is_debug_logging_enabled() {
        $settings = Installer::get_settings();
        return ! empty( $settings['enable_debug_logging'] );
    }
}

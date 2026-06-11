<?php
/**
 * Main plugin bootstrap.
 *
 * @package WpNagTerminator
 */

namespace WpNagTerminator;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Plugin {

    /**
     * Singleton instance.
     *
     * @var Plugin|null
     */
    private static $instance = null;

    /**
     * Detector instance.
     *
     * @var Detector
     */
    public $detector;

    /**
     * Suppressor instance.
     *
     * @var Suppressor
     */
    public $suppressor;

    /**
     * Storage instance.
     *
     * @var Storage
     */
    public $storage;

    /**
     * AJAX handler instance.
     *
     * @var Ajax
     */
    public $ajax;

    /**
     * Assets instance.
     *
     * @var Assets
     */
    public $assets;

    /**
     * Admin page instance.
     *
     * @var Admin_Page
     */
    public $admin_page;

    /**
     * Plugin links instance.
     *
     * @var Plugin_Links
     */
    public $plugin_links;

    /**
     * Get/create singleton.
     *
     * @return Plugin
     */
    public static function instance() {
        if ( null === self::$instance ) {
            self::$instance = new self();
            self::$instance->boot();
        }
        return self::$instance;
    }

    /**
     * Private constructor.
     */
    private function __construct() {}

    /**
     * Wire up everything.
     */
    private function boot() {
        $this->storage       = new Storage();
        $this->detector      = new Detector( $this->storage );
        $this->suppressor    = new Suppressor( $this->storage );
        $this->ajax          = new Ajax( $this->storage );
        $this->assets        = new Assets( $this->detector );
        $this->admin_page    = new Admin_Page( $this->storage );
        $this->plugin_links  = new Plugin_Links();

        $this->detector->register();
        $this->suppressor->register();
        $this->ajax->register();
        $this->assets->register();
        $this->admin_page->register();
        $this->plugin_links->register();

        // One-time backfill: for any existing dismissals that have a
        // stored prefix in the old format (or no prefix at all),
        // clear the prefix so the next render computes a fresh one
        // from the current (consistent) algorithm. This handles
        // upgrades from 1.1.x to 1.1.3 where the prefix algorithm
        // changed.
        self::maybe_backfill_prefixes();

        load_plugin_textdomain(
            'wp-nag-terminator',
            false,
            dirname( WP_NAG_TERMINATOR_BASENAME ) . '/languages'
        );
    }

    /**
     * Run once: clear any non-matching prefix fingerprints in stored
     * dismissals so the next user dismiss re-computes a fresh one
     * with the current algorithm. Idempotent: no-op after first run.
     */
    private static function maybe_backfill_prefixes() {
        if ( get_option( Storage::PREFIX_BACKFILL_OPTION ) ) {
            return; // already done
        }
        $global = get_option( 'wp_nag_terminator_global_dismissed', array() );
        $changed = false;
        if ( is_array( $global ) ) {
            foreach ( $global as $nag_id => &$meta ) {
                if ( is_array( $meta ) && isset( $meta['prefix'] ) ) {
                    // Clear the prefix; it'll be re-computed on next dismiss.
                    unset( $meta['prefix'] );
                    $changed = true;
                }
            }
            unset( $meta );
            if ( $changed ) {
                update_option( 'wp_nag_terminator_global_dismissed', $global );
            }
        }
        // Per-user dismissal maps.
        global $wpdb;
        $rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT user_id, meta_value FROM {$wpdb->usermeta} WHERE meta_key = %s",
                Storage::META_USER
            )
        );
        if ( is_array( $rows ) ) {
            foreach ( $rows as $row ) {
                $map = maybe_unserialize( $row->meta_value );
                if ( ! is_array( $map ) ) {
                    continue;
                }
                $user_changed = false;
                foreach ( $map as $nag_id => &$meta ) {
                    if ( is_array( $meta ) && isset( $meta['prefix'] ) ) {
                        unset( $meta['prefix'] );
                        $user_changed = true;
                    }
                }
                unset( $meta );
                if ( $user_changed ) {
                    update_user_meta( (int) $row->user_id, Storage::META_USER, $map );
                }
            }
        }
        // Mark as done so we never run this again.
        update_option( Storage::PREFIX_BACKFILL_OPTION, time(), false );
    }
}

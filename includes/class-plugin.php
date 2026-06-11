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
        $this->storage     = new Storage();
        $this->detector    = new Detector( $this->storage );
        $this->suppressor  = new Suppressor( $this->storage );
        $this->ajax        = new Ajax( $this->storage );
        $this->assets      = new Assets( $this->detector );
        $this->admin_page  = new Admin_Page( $this->storage );

        $this->detector->register();
        $this->suppressor->register();
        $this->ajax->register();
        $this->assets->register();
        $this->admin_page->register();

        load_plugin_textdomain(
            'wp-nag-terminator',
            false,
            dirname( WP_NAG_TERMINATOR_BASENAME ) . '/languages'
        );
    }
}

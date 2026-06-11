<?php
/**
 * Enqueue CSS/JS for the admin.
 *
 * @package WpNagTerminator
 */

namespace WpNagTerminator;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Assets {

    /**
     * Detector instance.
     *
     * @var Detector
     */
    private $detector;

    /**
     * Constructor.
     *
     * @param Detector $detector Detector instance.
     */
    public function __construct( Detector $detector ) {
        $this->detector = $detector;
    }

    /**
     * Register hooks.
     */
    public function register() {
        add_action( 'admin_enqueue_scripts', array( $this, 'enqueue' ) );
    }

    /**
     * Enqueue scripts + styles.
     */
    public function enqueue() {
        $screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
        $is_our_page = $screen && ( false !== strpos( $screen->id, 'wp-nag-terminator' ) );

        wp_enqueue_style(
            'wp-nag-terminator',
            WP_NAG_TERMINATOR_URL . 'assets/css/admin.css',
            array(),
            WP_NAG_TERMINATOR_VERSION
        );

        wp_enqueue_script(
            'wp-nag-terminator',
            WP_NAG_TERMINATOR_URL . 'assets/js/admin.js',
            array( 'jquery' ),
            WP_NAG_TERMINATOR_VERSION,
            true
        );

        wp_localize_script(
            'wp-nag-terminator',
            'WpNagTerminator',
            array(
                'ajaxUrl' => admin_url( 'admin-ajax.php' ),
                'nonce'   => wp_create_nonce( Ajax::NONCE_ACTION ),
                'i18n'    => array(
                    'confirmAll'   => __( 'Terminate for all admins?', 'wp-nag-terminator' ),
                    'restore'      => __( 'Restored.', 'wp-nag-terminator' ),
                    'terminated'   => __( 'Terminated.', 'wp-nag-terminator' ),
                    'undo'         => __( 'Undo', 'wp-nag-terminator' ),
                    'error'        => __( 'Something went wrong.', 'wp-nag-terminator' ),
                ),
            )
        );

        if ( $is_our_page ) {
            // Add a small inline stylesheet tweak for our admin page.
            wp_add_inline_style(
                'wp-nag-terminator',
                '.wp-nag-terminator-wrap .nav-tab-wrapper{margin-bottom:1em;}'
            );
        }
    }
}

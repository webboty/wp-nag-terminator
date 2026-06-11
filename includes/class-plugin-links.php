<?php
/**
 * Plugin links: settings shortcut + GitHub row meta.
 *
 * @package WpNagTerminator
 */

namespace WpNagTerminator;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Plugin_Links {

    /**
     * GitHub repo URL.
     */
    const REPO_URL = 'https://github.com/webboty/wp-nag-terminator';

    /**
     * Register hooks.
     */
    public function register() {
        add_filter( 'plugin_action_links_' . WP_NAG_TERMINATOR_BASENAME, array( $this, 'action_links' ) );
        add_filter( 'plugin_row_meta', array( $this, 'row_meta' ), 10, 2 );
    }

    /**
     * Override the Settings action link to point straight to the
     * Tools → NAG Terminator → Settings tab.
     *
     * @param array $links Existing action links.
     * @return array
     */
    public function action_links( $links ) {
        if ( ! is_array( $links ) ) {
            return $links;
        }
        $settings_url = add_query_arg(
            array(
                'page' => Admin_Page::MENU_SLUG,
                'tab'  => 'settings',
            ),
            admin_url( 'tools.php' )
        );
        $settings_link = '<a href="' . esc_url( $settings_url ) . '">' . esc_html__( 'Settings', 'wp-nag-terminator' ) . '</a>';

        // Put Settings first, then the rest (Deactivate, etc.).
        $new = array_merge( array( 'settings' => $settings_link ), $links );
        return $new;
    }

    /**
     * Add "View on GitHub" + "Documentation" links to the plugin row meta.
     *
     * @param array  $links Existing meta links.
     * @param string $file  Plugin basename.
     * @return array
     */
    public function row_meta( $links, $file ) {
        if ( $file !== WP_NAG_TERMINATOR_BASENAME ) {
            return $links;
        }
        $links[] = '<a href="' . esc_url( self::REPO_URL ) . '" target="_blank" rel="noopener noreferrer">' . esc_html__( 'View on GitHub', 'wp-nag-terminator' ) . '</a>';
        $links[] = '<a href="' . esc_url( self::REPO_URL . '/issues' ) . '" target="_blank" rel="noopener noreferrer">' . esc_html__( 'Report an issue', 'wp-nag-terminator' ) . '</a>';
        return $links;
    }
}

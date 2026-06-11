<?php
/**
 * Tools → NAG Terminator admin page.
 *
 * @package WpNagTerminator
 */

namespace WpNagTerminator;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Admin_Page {

    const MENU_SLUG    = 'wp-nag-terminator';
    const CAP_REQUIRED = 'read';

    /**
     * Storage instance.
     *
     * @var Storage
     */
    private $storage;

    /**
     * Constructor.
     *
     * @param Storage $storage Storage instance.
     */
    public function __construct( Storage $storage ) {
        $this->storage = $storage;
    }

    /**
     * Register hooks.
     */
    public function register() {
        add_action( 'admin_menu', array( $this, 'add_menu' ) );
        add_action( 'admin_post_wp_nag_terminator_save_settings', array( $this, 'handle_save_settings' ) );
    }

    /**
     * Add the Tools submenu.
     */
    public function add_menu() {
        add_submenu_page(
            'tools.php',
            __( 'NAG Terminator', 'wp-nag-terminator' ),
            __( 'NAG Terminator', 'wp-nag-terminator' ),
            self::CAP_REQUIRED,
            self::MENU_SLUG,
            array( $this, 'render_page' )
        );
    }

    /**
     * Render the page.
     */
    public function render_page() {
        if ( ! current_user_can( self::CAP_REQUIRED ) ) {
            wp_die( esc_html__( 'You do not have permission to view this page.', 'wp-nag-terminator' ) );
        }

        $tab  = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : 'active'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        $tabs = array(
            'active'  => __( 'Currently visible', 'wp-nag-terminator' ),
            'mine'    => __( 'My hidden NAGs', 'wp-nag-terminator' ),
            'global'  => __( 'Terminated for everyone', 'wp-nag-terminator' ),
            'archive' => __( 'Recycle bin', 'wp-nag-terminator' ),
        );
        if ( ! isset( $tabs[ $tab ] ) ) {
            $tab = 'active';
        }

        $is_admin    = Capabilities::can_manage_settings();
        $archive     = Storage::get_archive();
        $user_map    = Storage::get_user_dismissed( get_current_user_id() );
        $global_map  = Storage::get_global_dismissed();

        echo '<div class="wrap wp-nag-terminator-wrap">';
        echo '<h1>' . esc_html__( 'NAG Terminator', 'wp-nag-terminator' ) . '</h1>';

        if ( $is_admin ) {
            echo '<nav class="nav-tab-wrapper">';
            foreach ( $tabs as $key => $label ) {
                $active = ( $key === $tab ) ? ' nav-tab-active' : '';
                $url    = add_query_arg( 'tab', $key, menu_page_url( self::MENU_SLUG, false ) );
                echo '<a class="nav-tab' . esc_attr( $active ) . '" href="' . esc_url( $url ) . '">' . esc_html( $label ) . '</a>';
            }
            echo '</nav>';
        }

        echo '<div class="wp-nag-terminator-tab">';
        switch ( $tab ) {
            case 'mine':
                $this->render_list(
                    $user_map,
                    __( 'You have not hidden any NAGs yet.', 'wp-nag-terminator' ),
                    'user'
                );
                break;
            case 'global':
                if ( ! $is_admin ) {
                    wp_die( esc_html__( 'Insufficient capabilities.', 'wp-nag-terminator' ) );
                }
                $this->render_list(
                    $global_map,
                    __( 'No NAGs have been globally terminated.', 'wp-nag-terminator' ),
                    'global'
                );
                break;
            case 'archive':
                if ( ! $is_admin ) {
                    wp_die( esc_html__( 'Insufficient capabilities.', 'wp-nag-terminator' ) );
                }
                $this->render_archive( $archive );
                break;
            case 'active':
            default:
                $this->render_active();
                break;
        }
        echo '</div>';

        if ( $is_admin ) {
            echo '<hr/>';
            $this->render_settings();
        }

        echo '</div>';
    }

    /**
     * Render a list of dismissed NAGs with [Restore] buttons.
     *
     * @param array  $map       Map of nag_id => meta.
     * @param string $empty_msg Empty-state message.
     * @param string $scope     'user' | 'global'.
     */
    private function render_list( array $map, $empty_msg, $scope ) {
        if ( empty( $map ) ) {
            echo '<p>' . esc_html( $empty_msg ) . '</p>';
            return;
        }
        echo '<table class="widefat striped">';
        echo '<thead><tr>';
        echo '<th>' . esc_html__( 'Excerpt', 'wp-nag-terminator' ) . '</th>';
        echo '<th>' . esc_html__( 'Source', 'wp-nag-terminator' ) . '</th>';
        echo '<th>' . esc_html__( 'Terminated', 'wp-nag-terminator' ) . '</th>';
        echo '<th>' . esc_html__( 'Actions', 'wp-nag-terminator' ) . '</th>';
        echo '</tr></thead><tbody>';

        foreach ( $map as $nag_id => $meta ) {
            $excerpt   = isset( $meta['excerpt'] ) ? $meta['excerpt'] : '';
            $source    = isset( $meta['source'] ) ? $meta['source'] : '';
            $timestamp = isset( $meta['dismissed'] ) ? (int) $meta['dismissed'] : 0;
            $time_str  = $timestamp ? date_i18n( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $timestamp ) : '—';
            echo '<tr>';
            echo '<td>' . esc_html( wp_trim_words( $excerpt, 25, '...' ) ) . '</td>';
            echo '<td>' . esc_html( $source ?: '—' ) . '</td>';
            echo '<td>' . esc_html( $time_str ) . '</td>';
            echo '<td><button type="button" class="button wp-nag-terminator-restore" data-nag-id="' . esc_attr( $nag_id ) . '" data-scope="' . esc_attr( $scope ) . '">' . esc_html__( 'Restore', 'wp-nag-terminator' ) . '</button></td>';
            echo '</tr>';
        }
        echo '</tbody></table>';
    }

    /**
     * Render the recycle bin.
     *
     * @param array $archive Full archive.
     */
    private function render_archive( array $archive ) {
        if ( empty( $archive ) ) {
            echo '<p>' . esc_html__( 'The recycle bin is empty.', 'wp-nag-terminator' ) . '</p>';
            return;
        }
        echo '<p class="description">' . esc_html__( 'Older versions of dismissed NAGs are kept here. You can restore them or delete them permanently.', 'wp-nag-terminator' ) . '</p>';
        echo '<table class="widefat striped">';
        echo '<thead><tr>';
        echo '<th>' . esc_html__( 'Excerpt', 'wp-nag-terminator' ) . '</th>';
        echo '<th>' . esc_html__( 'Source', 'wp-nag-terminator' ) . '</th>';
        echo '<th>' . esc_html__( 'Scope', 'wp-nag-terminator' ) . '</th>';
        echo '<th>' . esc_html__( 'Terminated', 'wp-nag-terminator' ) . '</th>';
        echo '<th>' . esc_html__( 'Actions', 'wp-nag-terminator' ) . '</th>';
        echo '</tr></thead><tbody>';

        // Newest first.
        uasort( $archive, function ( $a, $b ) {
            return ( (int) ( $b['terminated_at'] ?? 0 ) ) <=> ( (int) ( $a['terminated_at'] ?? 0 ) );
        } );

        foreach ( $archive as $nag_id => $entry ) {
            $time_str = ! empty( $entry['terminated_at'] )
                ? date_i18n( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), (int) $entry['terminated_at'] )
                : '—';
            $scope    = isset( $entry['scope'] ) ? $entry['scope'] : 'global';
            echo '<tr>';
            echo '<td>' . esc_html( wp_trim_words( $entry['excerpt'] ?? '', 25, '...' ) ) . '</td>';
            echo '<td>' . esc_html( $entry['source_label'] ?? ( $entry['source'] ?? '—' ) ) . '</td>';
            echo '<td>' . esc_html( $scope ) . '</td>';
            echo '<td>' . esc_html( $time_str ) . '</td>';
            echo '<td>';
            echo '<button type="button" class="button wp-nag-terminator-restore" data-nag-id="' . esc_attr( $nag_id ) . '" data-scope="' . esc_attr( $scope ) . '">' . esc_html__( 'Restore', 'wp-nag-terminator' ) . '</button> ';
            echo '<button type="button" class="button wp-nag-terminator-delete" data-nag-id="' . esc_attr( $nag_id ) . '">' . esc_html__( 'Delete permanently', 'wp-nag-terminator' ) . '</button>';
            echo '</td>';
            echo '</tr>';
        }
        echo '</tbody></table>';
    }

    /**
     * Render "currently visible" snapshot (live on this page).
     */
    private function render_active() {
        $detector = Plugin::instance()->detector;
        $detected = is_object( $detector ) ? $detector->detected : array();
        if ( empty( $detected ) ) {
            echo '<p>' . esc_html__( 'No NAGs are visible on this page. Visit any admin page to see the currently visible NAGs.', 'wp-nag-terminator' ) . '</p>';
            return;
        }
        echo '<table class="widefat striped">';
        echo '<thead><tr>';
        echo '<th>' . esc_html__( 'Excerpt', 'wp-nag-terminator' ) . '</th>';
        echo '<th>' . esc_html__( 'Source', 'wp-nag-terminator' ) . '</th>';
        echo '<th>' . esc_html__( 'NAG ID', 'wp-nag-terminator' ) . '</th>';
        echo '</tr></thead><tbody>';
        foreach ( $detected as $entry ) {
            echo '<tr>';
            echo '<td>' . esc_html( wp_trim_words( $entry['excerpt'] ?? '', 25, '...' ) ) . '</td>';
            echo '<td>' . esc_html( $entry['source'] ?? '—' ) . '</td>';
            echo '<td><code>' . esc_html( $entry['id'] ?? '' ) . '</code></td>';
            echo '</tr>';
        }
        echo '</tbody></table>';
    }

    /**
     * Render the settings form.
     */
    private function render_settings() {
        $settings = Installer::get_settings();
        $roles    = wp_roles()->roles;
        $bypass   = isset( $settings['bypass_global_roles'] ) ? (array) $settings['bypass_global_roles'] : array();

        echo '<h2>' . esc_html__( 'Settings', 'wp-nag-terminator' ) . '</h2>';
        echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
        wp_nonce_field( 'wp_nag_terminator_save_settings' );
        echo '<input type="hidden" name="action" value="wp_nag_terminator_save_settings" />';
        echo '<table class="form-table"><tbody>';

        echo '<tr><th>' . esc_html__( 'Auto-archive on dismiss', 'wp-nag-terminator' ) . '</th><td>';
        echo '<label><input type="checkbox" name="auto_archive" value="1" ' . checked( ! empty( $settings['auto_archive'] ), true, false ) . '/> ';
        echo esc_html__( 'Save dismissed NAGs to the recycle bin.', 'wp-nag-terminator' ) . '</label>';
        echo '</td></tr>';

        echo '<tr><th>' . esc_html__( 'Retention (days)', 'wp-nag-terminator' ) . '</th><td>';
        echo '<input type="number" name="retention_days" min="0" value="' . esc_attr( (string) $settings['retention_days'] ) . '" /> ';
        echo '<p class="description">' . esc_html__( 'Set to 0 to keep forever. Old entries are purged daily.', 'wp-nag-terminator' ) . '</p>';
        echo '</td></tr>';

        echo '<tr><th>' . esc_html__( 'Bypass global terminations', 'wp-nag-terminator' ) . '</th><td>';
        echo '<p class="description">' . esc_html__( 'Selected roles will continue to see NAGs even when "Terminate for everyone" is in effect.', 'wp-nag-terminator' ) . '</p>';
        foreach ( $roles as $role_key => $role_def ) {
            $checked = in_array( $role_key, $bypass, true ) ? 'checked' : '';
            echo '<label style="display:block;margin-bottom:4px;"><input type="checkbox" name="bypass_global_roles[]" value="' . esc_attr( $role_key ) . '" ' . esc_attr( $checked ) . '/> ' . esc_html( translate_user_role( $role_def['name'] ) ) . '</label>';
        }
        echo '</td></tr>';

        echo '<tr><th>' . esc_html__( 'Action link visibility', 'wp-nag-terminator' ) . '</th><td>';
        $vis = $settings['action_link_visibility'];
        echo '<label><input type="radio" name="action_link_visibility" value="always" ' . checked( $vis, 'always', false ) . '/> ' . esc_html__( 'Always visible', 'wp-nag-terminator' ) . '</label><br/>';
        echo '<label><input type="radio" name="action_link_visibility" value="hover" ' . checked( $vis, 'hover', false ) . '/> ' . esc_html__( 'On hover/focus only', 'wp-nag-terminator' ) . '</label>';
        echo '</td></tr>';

        echo '</tbody></table>';
        submit_button( __( 'Save settings', 'wp-nag-terminator' ) );
        echo '</form>';
    }

    /**
     * Handle settings save.
     */
    public function handle_save_settings() {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( esc_html__( 'Insufficient capabilities.', 'wp-nag-terminator' ) );
        }
        check_admin_referer( 'wp_nag_terminator_save_settings' );

        Installer::update_setting( 'auto_archive', ! empty( $_POST['auto_archive'] ) ? 1 : 0 );
        Installer::update_setting( 'retention_days', max( 0, (int) ( $_POST['retention_days'] ?? 0 ) ) );
        Installer::update_setting(
            'bypass_global_roles',
            isset( $_POST['bypass_global_roles'] ) && is_array( $_POST['bypass_global_roles'] )
                ? array_values( array_filter( array_map( 'sanitize_key', wp_unslash( $_POST['bypass_global_roles'] ) ) ) )
                : array()
        );
        $vis = isset( $_POST['action_link_visibility'] ) ? sanitize_key( wp_unslash( $_POST['action_link_visibility'] ) ) : 'always';
        if ( ! in_array( $vis, array( 'always', 'hover' ), true ) ) {
            $vis = 'always';
        }
        Installer::update_setting( 'action_link_visibility', $vis );

        wp_safe_redirect( add_query_arg( 'updated', '1', wp_get_referer() ) );
        exit;
    }
}

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
            'active'      => __( 'Currently visible', 'wp-nag-terminator' ),
            'mine'        => __( 'My hidden NAGs', 'wp-nag-terminator' ),
            'global'      => __( 'NAGs hidden for everyone', 'wp-nag-terminator' ),
            'log'         => __( 'Log', 'wp-nag-terminator' ),
            'docs'        => __( 'Documentation', 'wp-nag-terminator' ),
            'settings'    => __( 'Settings', 'wp-nag-terminator' ),
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
                    __( 'No NAGs have been hidden for everyone yet.', 'wp-nag-terminator' ),
                    'global'
                );
                break;
            case 'log':
                if ( ! $is_admin ) {
                    wp_die( esc_html__( 'Insufficient capabilities.', 'wp-nag-terminator' ) );
                }
                $this->render_log( $archive, $user_map, $global_map );
                break;
            case 'docs':
                $this->render_docs();
                break;
            case 'settings':
                if ( ! $is_admin ) {
                    wp_die( esc_html__( 'Insufficient capabilities.', 'wp-nag-terminator' ) );
                }
                $this->render_settings();
                break;
            case 'active':
            default:
                $this->render_active();
                break;
        }
        echo '</div>';

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
        echo '<th>' . esc_html__( 'Hidden on', 'wp-nag-terminator' ) . '</th>';
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
     * Render the log: read-only history of all hidden NAGs.
     *
     * @param array $archive    Full archive.
     * @param array $user_map   Per-user hidden NAGs.
     * @param array $global_map Globally hidden NAGs.
     */
    private function render_log( array $archive, array $user_map, array $global_map ) {
        echo '<p class="description">' . esc_html__( 'A read-only history of every NAG that has been hidden on this site, by any user. Use the My hidden NAGs and NAGs hidden for everyone tabs to restore.', 'wp-nag-terminator' ) . '</p>';

        $rows = array();
        $seen = array();

        // Current user-hidden + global-hidden first (most useful).
        foreach ( $global_map as $nag_id => $meta ) {
            $rows[] = array(
                'nag_id'  => $nag_id,
                'excerpt' => isset( $meta['excerpt'] ) ? $meta['excerpt'] : '',
                'source'  => isset( $meta['source'] ) ? $meta['source'] : '',
                'scope'   => 'global',
                'time'    => isset( $meta['dismissed'] ) ? (int) $meta['dismissed'] : 0,
                'status'  => 'active',
                'content' => '',
            );
            $seen[ $nag_id ] = true;
        }
        foreach ( $user_map as $nag_id => $meta ) {
            if ( isset( $seen[ $nag_id ] ) ) {
                continue;
            }
            $rows[] = array(
                'nag_id'  => $nag_id,
                'excerpt' => isset( $meta['excerpt'] ) ? $meta['excerpt'] : '',
                'source'  => isset( $meta['source'] ) ? $meta['source'] : '',
                'scope'   => 'me',
                'time'    => isset( $meta['dismissed'] ) ? (int) $meta['dismissed'] : 0,
                'status'  => 'active',
                'content' => '',
            );
            $seen[ $nag_id ] = true;
        }

        // Archived (historical) entries.
        foreach ( $archive as $nag_id => $entry ) {
            if ( isset( $seen[ $nag_id ] ) ) {
                // Update the active row with full content from archive if present.
                foreach ( $rows as $idx => $r ) {
                    if ( $r['nag_id'] === $nag_id ) {
                        $rows[ $idx ]['content'] = isset( $entry['content'] ) ? $entry['content'] : '';
                        break;
                    }
                }
                continue;
            }
            $rows[] = array(
                'nag_id'  => $nag_id,
                'excerpt' => isset( $entry['excerpt'] ) ? $entry['excerpt'] : '',
                'source'  => isset( $entry['source_label'] ) ? $entry['source_label'] : ( isset( $entry['source'] ) ? $entry['source'] : '' ),
                'scope'   => isset( $entry['scope'] ) ? $entry['scope'] : 'global',
                'time'    => isset( $entry['terminated_at'] ) ? (int) $entry['terminated_at'] : 0,
                'status'  => 'archived',
                'content' => isset( $entry['content'] ) ? $entry['content'] : '',
            );
        }

        if ( empty( $rows ) ) {
            echo '<p>' . esc_html__( 'The log is empty.', 'wp-nag-terminator' ) . '</p>';
            return;
        }

        // Sort newest first.
        usort( $rows, function ( $a, $b ) {
            return $b['time'] <=> $a['time'];
        } );

        echo '<table class="widefat striped wp-nag-terminator-log">';
        echo '<thead><tr>';
        echo '<th>' . esc_html__( 'When', 'wp-nag-terminator' ) . '</th>';
        echo '<th>' . esc_html__( 'Scope', 'wp-nag-terminator' ) . '</th>';
        echo '<th>' . esc_html__( 'Source', 'wp-nag-terminator' ) . '</th>';
        echo '<th>' . esc_html__( 'Original notice', 'wp-nag-terminator' ) . '</th>';
        echo '</tr></thead><tbody>';

        foreach ( $rows as $row ) {
            $time_str = $row['time']
                ? date_i18n( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $row['time'] )
                : '—';
            $scope_label = ( 'me' === $row['scope'] ) ? __( 'Hidden by you', 'wp-nag-terminator' ) : __( 'Hidden for everyone', 'wp-nag-terminator' );
            $status_badge = ( 'archived' === $row['status'] )
                ? ' <span class="wp-nag-terminator-badge">' . esc_html__( 'archived', 'wp-nag-terminator' ) . '</span>'
                : '';

            echo '<tr>';
            echo '<td>' . esc_html( $time_str ) . $status_badge . '</td>';
            echo '<td>' . esc_html( $scope_label ) . '</td>';
            echo '<td>' . esc_html( $row['source'] ?: '—' ) . '</td>';
            echo '<td>';
            if ( ! empty( $row['content'] ) ) {
                $sanitized = Ajax::sanitize_notice_html( $row['content'] );
                // Strip data-nag-id so the Detector's output buffer does not
                // re-strip this notice (it would think it's a live notice to
                // hide). The log is a read-only history view.
                $sanitized = preg_replace( '/\s*data-nag-id="[^"]+"/i', '', $sanitized );
                echo '<div class="wp-nag-terminator-log-content">' . $sanitized . '</div>';
            } else {
                echo esc_html( wp_trim_words( $row['excerpt'], 40, '...' ) );
            }
            echo '</td>';
            echo '</tr>';
        }
        echo '</tbody></table>';
    }

    /**
     * Render the documentation tab.
     */
    private function render_docs() {
        ?>
        <div class="wp-nag-terminator-docs">
            <h2><?php esc_html_e( 'What is NAG Terminator?', 'wp-nag-terminator' ); ?></h2>
            <p><?php esc_html_e( 'NAG Terminator hides WordPress admin notice NAGs — the small messages plugins and core display at the top of admin pages. Some can be dismissed with a built-in close button; many cannot, and they come back every page load.', 'wp-nag-terminator' ); ?></p>

            <h3><?php esc_html_e( 'What the action links actually do', 'wp-nag-terminator' ); ?></h3>
            <p><?php esc_html_e( 'Despite the wording, the buttons do not run, fix, or accept anything the notice says. They only hide the notice from your view.', 'wp-nag-terminator' ); ?></p>
            <ul style="list-style: disc; padding-left: 24px;">
                <li><strong><?php esc_html_e( 'Hide for me', 'wp-nag-terminator' ); ?></strong> &mdash; <?php esc_html_e( 'Removes the notice only for your account. Other admins and staff continue to see it.', 'wp-nag-terminator' ); ?></li>
                <li><strong><?php esc_html_e( 'Hide for everyone', 'wp-nag-terminator' ); ?></strong> <em>(admins only)</em> &mdash; <?php esc_html_e( 'Removes the notice for every admin and staff user on the site.', 'wp-nag-terminator' ); ?></li>
            </ul>
            <p><?php esc_html_e( 'Hiding is reversible. You can restore any hidden NAG from the Tools → NAG Terminator page.', 'wp-nag-terminator' ); ?></p>

            <h3><?php esc_html_e( 'The tabs', 'wp-nag-terminator' ); ?></h3>
            <ul style="list-style: disc; padding-left: 24px;">
                <li><strong><?php esc_html_e( 'Currently visible', 'wp-nag-terminator' ); ?></strong> &mdash; <?php esc_html_e( 'A live snapshot of NAGs on the current admin page.', 'wp-nag-terminator' ); ?></li>
                <li><strong><?php esc_html_e( 'My hidden NAGs', 'wp-nag-terminator' ); ?></strong> &mdash; <?php esc_html_e( 'NAGs you personally have hidden. Restore any of them with one click.', 'wp-nag-terminator' ); ?></li>
                <li><strong><?php esc_html_e( 'NAGs hidden for everyone', 'wp-nag-terminator' ); ?></strong> &mdash; <?php esc_html_e( 'NAGs an admin has hidden site-wide. Admin only.', 'wp-nag-terminator' ); ?></li>
                <li><strong><?php esc_html_e( 'Log', 'wp-nag-terminator' ); ?></strong> &mdash; <?php esc_html_e( 'A read-only history of every hidden NAG on this site, including older versions whose text has since changed.', 'wp-nag-terminator' ); ?></li>
                <li><strong><?php esc_html_e( 'Settings', 'wp-nag-terminator' ); ?></strong> &mdash; <?php esc_html_e( 'Configure retention, role bypass, and link visibility.', 'wp-nag-terminator' ); ?></li>
            </ul>

            <h3><?php esc_html_e( 'Tips', 'wp-nag-terminator' ); ?></h3>
            <ul style="list-style: disc; padding-left: 24px;">
                <li><?php esc_html_e( 'A 10-second Undo toast appears after hiding a NAG. Use it if you change your mind.', 'wp-nag-terminator' ); ?></li>
                <li><?php esc_html_e( 'If you still want to see globally-hidden NAGs, ask an admin to add your role to the "Bypass global terminations" list in Settings.', 'wp-nag-terminator' ); ?></li>
                <li><?php esc_html_e( 'Some notices change their text frequently (ads, version numbers). Each version gets its own entry, so old versions stay in the Log.', 'wp-nag-terminator' ); ?></li>
            </ul>
        </div>
        <?php
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

        echo '<tr><th>' . esc_html__( 'Retention (days)', 'wp-nag-terminator' ) . '</th><td>';
        echo '<input type="number" name="retention_days" min="0" value="' . esc_attr( (string) $settings['retention_days'] ) . '" /> ';
        echo '<p class="description">' . esc_html__( 'Set to 0 to keep forever. Old entries are purged daily.', 'wp-nag-terminator' ) . '</p>';
        echo '</td></tr>';

        echo '<tr><th>' . esc_html__( 'Bypass global terminations', 'wp-nag-terminator' ) . '</th><td>';
        echo '<p class="description">' . esc_html__( 'Selected roles will continue to see NAGs even when "Hide for everyone" is in effect.', 'wp-nag-terminator' ) . '</p>';
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

<?php
/**
 * AJAX endpoints: terminate / restore.
 *
 * @package WpNagTerminator
 */

namespace WpNagTerminator;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Ajax {

    const NONCE_ACTION = 'wp_nag_terminator';

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
        add_action( 'wp_ajax_wp_nag_terminator_terminate', array( $this, 'terminate' ) );
        add_action( 'wp_ajax_wp_nag_terminator_restore', array( $this, 'restore' ) );
        add_action( 'wp_ajax_wp_nag_terminator_delete', array( $this, 'delete_archive' ) );
    }

    /**
     * Verify nonce + return user id; or send -1.
     *
     * @return int|\WP_Error
     */
    private function verify() {
        if ( ! check_ajax_referer( self::NONCE_ACTION, 'nonce', false ) ) {
            return new \WP_Error( 'forbidden', __( 'Invalid nonce.', 'wp-nag-terminator' ), array( 'status' => 403 ) );
        }
        if ( ! is_user_logged_in() ) {
            return new \WP_Error( 'forbidden', __( 'Login required.', 'wp-nag-terminator' ), array( 'status' => 401 ) );
        }
        return (int) get_current_user_id();
    }

    /**
     * GET endpoint: used by the admin page to fetch current lists.
     */
    public function list_nags() {
        // Unused; admin page renders via PHP directly.
        wp_send_json_error( array( 'message' => __( 'Not implemented.', 'wp-nag-terminator' ) ), 501 );
    }

    /**
     * AJAX: terminate a NAG (user or global scope).
     */
    public function terminate() {
        $uid = $this->verify();
        if ( is_wp_error( $uid ) ) {
            wp_send_json_error( array( 'message' => $uid->get_error_message() ), $uid->get_error_data()['status'] ?? 400 );
        }

        $nag_id = isset( $_POST['nag_id'] ) ? (string) wp_unslash( $_POST['nag_id'] ) : '';
        $scope  = isset( $_POST['scope'] ) ? sanitize_key( wp_unslash( $_POST['scope'] ) ) : 'user';
        $excerpt = isset( $_POST['excerpt'] ) ? sanitize_text_field( wp_unslash( $_POST['excerpt'] ) ) : '';
        $source  = isset( $_POST['source'] ) ? sanitize_key( wp_unslash( $_POST['source'] ) ) : '';
        $content = isset( $_POST['content'] ) ? self::sanitize_notice_html( wp_unslash( $_POST['content'] ) ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
        $prefix  = isset( $_POST['prefix'] ) ? sanitize_text_field( wp_unslash( $_POST['prefix'] ) ) : '';

        if ( ! self::is_valid_nag_id( $nag_id ) ) {
            wp_send_json_error( array( 'message' => __( 'Invalid NAG id.', 'wp-nag-terminator' ) ), 400 );
        }

        // Strip our own action bar from the stored content so the Log shows
        // the original notice only.
        $content = self::strip_action_bar( $content );

        $meta = array(
            'excerpt'      => $excerpt,
            'source'       => $source,
            'source_hook'  => $source,
            'source_label' => $source,
            'content'      => $content,
            'prefix'       => $prefix,
        );

        if ( 'global' === $scope ) {
            if ( ! Capabilities::can_dismiss_global() ) {
                wp_send_json_error( array( 'message' => __( 'Insufficient capabilities.', 'wp-nag-terminator' ) ), 403 );
            }
            $ok = Storage::dismiss_global( $nag_id, $uid, $meta );
            // Always archive (the archive is what feeds the Log).
            if ( $ok ) {
                Storage::archive( $nag_id, $uid, 'global', $meta );
            }
        } else {
            $ok = Storage::dismiss_for_user( $uid, $nag_id, $meta );
            // Always archive so the Log shows what the current user has hidden.
            if ( $ok ) {
                Storage::archive( $nag_id, $uid, 'user', $meta );
            }
        }

        if ( ! $ok ) {
            wp_send_json_error( array( 'message' => __( 'Could not save dismissal.', 'wp-nag-terminator' ) ), 500 );
        }

        wp_send_json_success(
            array(
                'nag_id' => $nag_id,
                'scope'  => $scope,
                'user_id'=> $uid,
            )
        );
    }

    /**
     * Remove our action bar from a notice HTML string before persisting.
     *
     * The action bar is always the last child of the notice div. We strip
     * the outermost <div class="nag-terminator-actions">...</div> block
     * using a balanced-tag matcher (no regex).
     *
     * @param string $html Notice HTML.
     * @return string
     */
    private static function strip_action_bar( $html ) {
        if ( '' === $html ) {
            return $html;
        }
        $marker = '<div class="nag-terminator-actions"';
        $pos    = strpos( $html, $marker );
        if ( false === $pos ) {
            return $html;
        }
        // Walk forward, tracking depth, to find the matching </div>.
        $depth    = 0;
        $i        = $pos;
        $len      = strlen( $html );
        $in_tag   = false;
        $tag_open = false;
        while ( $i < $len ) {
            $c = $html[ $i ];
            if ( ! $in_tag && '<' === $c ) {
                $in_tag   = true;
                $tag_open = ( $i + 1 < $len && '/' !== $html[ $i + 1 ] );
                $i++;
                continue;
            }
            if ( $in_tag ) {
                if ( '>' === $c ) {
                    $in_tag = false;
                    if ( $tag_open ) {
                        $depth++;
                    } else {
                        $depth--;
                        if ( 0 === $depth ) {
                            // Found the matching </div> for our action bar.
                            return substr( $html, 0, $pos ) . substr( $html, $i + 1 );
                        }
                    }
                }
                $i++;
                continue;
            }
            $i++;
        }
        return $html;
    }

    /**
     * Sanitize notice HTML allowing the full set of tags/attrs commonly used
     * by WP core admin notices (and that are safe to re-render in our Log).
     *
     * @param string $html Raw notice HTML.
     * @return string
     */
    public static function sanitize_notice_html( $html ) {
        if ( ! is_string( $html ) || '' === $html ) {
            return '';
        }
        $allowed = array(
            'div'    => array(
                'class'      => true,
                'id'         => true,
                'data-nag-id'=> true,
            ),
            'p'      => array( 'class' => true ),
            'span'   => array( 'class' => true ),
            'a'      => array(
                'href'   => true,
                'class'  => true,
                'target' => true,
                'rel'    => true,
                'title'  => true,
            ),
            'strong' => array(),
            'em'     => array(),
            'b'      => array(),
            'i'      => array(),
            'br'     => array(),
            'code'   => array( 'class' => true ),
            'pre'    => array(),
            'small'  => array(),
            'ul'     => array( 'class' => true ),
            'ol'     => array( 'class' => true ),
            'li'     => array( 'class' => true ),
            'button' => array(
                'type'       => true,
                'class'      => true,
                'aria-label' => true,
                'disabled'   => true,
            ),
            'svg'    => array(
                'class'   => true,
                'width'   => true,
                'height'  => true,
                'viewbox' => true,
                'xmlns'   => true,
                'fill'    => true,
                'aria-hidden' => true,
            ),
            'path'   => array( 'd' => true, 'fill' => true ),
        );
        return wp_kses( $html, $allowed );
    }

    /**
     * AJAX: restore a NAG (user or global).
     */
    public function restore() {
        $uid = $this->verify();
        if ( is_wp_error( $uid ) ) {
            wp_send_json_error( array( 'message' => $uid->get_error_message() ), $uid->get_error_data()['status'] ?? 400 );
        }

        $nag_id = isset( $_POST['nag_id'] ) ? (string) wp_unslash( $_POST['nag_id'] ) : '';
        $scope  = isset( $_POST['scope'] ) ? sanitize_key( wp_unslash( $_POST['scope'] ) ) : 'user';

        if ( ! self::is_valid_nag_id( $nag_id ) ) {
            wp_send_json_error( array( 'message' => __( 'Invalid NAG id.', 'wp-nag-terminator' ) ), 400 );
        }

        if ( 'global' === $scope ) {
            if ( ! Capabilities::can_restore_global() ) {
                wp_send_json_error( array( 'message' => __( 'Insufficient capabilities.', 'wp-nag-terminator' ) ), 403 );
            }
            $ok = Storage::restore_global( $nag_id );
        } else {
            $ok = Storage::restore_for_user( $uid, $nag_id );
        }

        if ( ! $ok ) {
            wp_send_json_error( array( 'message' => __( 'Could not restore.', 'wp-nag-terminator' ) ), 500 );
        }

        wp_send_json_success(
            array(
                'nag_id' => $nag_id,
                'scope'  => $scope,
            )
        );
    }

    /**
     * AJAX: permanently delete an archive entry (admin only).
     */
    public function delete_archive() {
        $uid = $this->verify();
        if ( is_wp_error( $uid ) ) {
            wp_send_json_error( array( 'message' => $uid->get_error_message() ), $uid->get_error_data()['status'] ?? 400 );
        }
        if ( ! Capabilities::can_delete_archive() ) {
            wp_send_json_error( array( 'message' => __( 'Insufficient capabilities.', 'wp-nag-terminator' ) ), 403 );
        }

        $nag_id = isset( $_POST['nag_id'] ) ? (string) wp_unslash( $_POST['nag_id'] ) : '';
        if ( ! self::is_valid_nag_id( $nag_id ) ) {
            wp_send_json_error( array( 'message' => __( 'Invalid NAG id.', 'wp-nag-terminator' ) ), 400 );
        }
        $ok = Storage::delete_archive_entry( $nag_id );
        if ( ! $ok ) {
            wp_send_json_error( array( 'message' => __( 'Could not delete.', 'wp-nag-terminator' ) ), 500 );
        }
        wp_send_json_success( array( 'nag_id' => $nag_id ) );
    }

    /**
     * Validate nag_id format.
     *
     * @param string $nag_id NAG ID.
     * @return bool
     */
    public static function is_valid_nag_id( $nag_id ) {
        return is_string( $nag_id ) && (bool) preg_match( '/^nag_[a-f0-9]{6,40}$/', $nag_id );
    }
}

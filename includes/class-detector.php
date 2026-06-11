<?php
/**
 * Detector: captures admin notice output and fingerprints it.
 *
 * Strategy: we start an output buffer at admin_head that captures
 * anything echoed to the page from that point forward (covers
 * admin_notices, network_admin_notices, user_admin_notices, and the
 * newer admin_notice_* hooks). Notices that match a known
 * dismissed-ID are stripped in the buffer. Notices that don't match
 * get a data-nag-id attribute injected so the inline action bar can
 * be wired up client-side.
 *
 * @package WpNagTerminator
 */

namespace WpNagTerminator;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Detector {

    /**
     * Storage instance.
     *
     * @var Storage
     */
    private $storage;

    /**
     * Notices detected on this page, keyed by nag_id.
     *
     * @var array
     */
    public $detected = array();

    /**
     * Whether the buffer is active.
     *
     * @var bool
     */
    private $buffering = false;

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
        add_action( 'admin_head', array( $this, 'start_buffer' ), 1 );
        add_action( 'admin_footer', array( $this, 'end_buffer' ), PHP_INT_MAX );
        add_action( 'in_admin_header', array( $this, 'start_buffer' ), 1 );
        add_action( 'in_admin_footer', array( $this, 'end_buffer' ), PHP_INT_MAX );
    }

    /**
     * Start the output buffer that catches admin notice HTML.
     */
    public function start_buffer() {
        if ( $this->buffering ) {
            return;
        }
        if ( ! is_admin() ) {
            return;
        }
        if ( ! Capabilities::can_dismiss_for_user() ) {
            return;
        }
        $this->buffering = true;
        ob_start( array( $this, 'process_buffer' ) );
    }

    /**
     * End the output buffer (flushes via PHP).
     */
    public function end_buffer() {
        if ( ! $this->buffering ) {
            return;
        }
        if ( ob_get_level() > 0 ) {
            // ob_end_flush() returns false if no active buffer.
            @ob_end_flush();
        }
        $this->buffering = false;
    }

    /**
     * Process the buffered HTML: detect notices, fingerprint, strip
     * hidden ones, inject data attributes + action bar markers on
     * visible ones.
     *
     * @param string $html Buffered output chunk.
     * @return string
     */
    public function process_buffer( $html ) {
        if ( '' === $html || false === strpos( $html, '<' ) ) {
            return $html;
        }

        $hidden_ids = Storage::get_hidden_ids_for_user();
        $can_global = Capabilities::can_dismiss_global();

        // Find every <div ... class="notice ..."> block (incl. notice-error,
        // notice-warning, notice-info, notice-success and their sub-classes).
        $pattern = '#<div\b[^>]*class\s*=\s*["\'][^"\']*\bnotice\b[^"\']*["\'][^>]*>.*?</div>#is';

        $result = preg_replace_callback(
            $pattern,
            function ( $matches ) use ( $hidden_ids, $can_global, &$detected_ref ) {
                $notice_html = $matches[0];

                $excerpt    = $this->make_excerpt( $notice_html );
                $source     = $this->guess_source_label( $notice_html );
                $nag_id     = $this->fingerprint( $notice_html, $source );

                // Store for the current request.
                $this->detected[ $nag_id ] = array(
                    'id'            => $nag_id,
                    'excerpt'       => $excerpt,
                    'source'        => $source,
                    'is_dismissable'=> $this->is_dismissable_html( $notice_html ),
                );

                // Hide if user or globally dismissed.
                if ( in_array( $nag_id, $hidden_ids, true ) ) {
                    return ''; // strip entirely
                }

                // Inject data attribute + action bar marker just before </div>.
                $actions = $this->render_action_bar( $nag_id, $can_global, $this->detected[ $nag_id ]['is_dismissable'] );
                $tagged  = preg_replace(
                    '#</div>\s*$#i',
                    $actions . '</div>',
                    $notice_html,
                    1
                );
                if ( null === $tagged ) {
                    // Couldn't find closing tag — append and add data attr on the first div.
                    $tagged = $this->inject_data_attr( $notice_html, $nag_id ) . $actions;
                } else {
                    $tagged = $this->inject_data_attr( $tagged, $nag_id );
                }
                return $tagged;
            },
            $html
        );

        if ( null === $result ) {
            return $html;
        }
        return $result;
    }

    /**
     * Render the inline action bar HTML appended to a notice.
     *
     * @param string $nag_id        NAG ID.
     * @param bool   $can_global    Whether the current user can dismiss globally.
     * @param bool   $is_dismissable Whether the notice has a WP dismiss button.
     * @return string
     */
    private function render_action_bar( $nag_id, $can_global, $is_dismissable ) {
        $me_label  = $is_dismissable
            ? esc_html__( 'Hide for me', 'wp-nag-terminator' )
            : esc_html__( 'Force-hide for me', 'wp-nag-terminator' );
        $all_label = $is_dismissable
            ? esc_html__( 'Terminate for everyone', 'wp-nag-terminator' )
            : esc_html__( 'Force-terminate for everyone', 'wp-nag-terminator' );

        $html  = '<div class="nag-terminator-actions" data-nag-id="' . esc_attr( $nag_id ) . '">';
        $html .= '<button type="button" class="button-link nag-terminator-me">' . $me_label . '</button>';

        if ( $can_global ) {
            $html .= '<span class="nag-terminator-sep">|</span>';
            $html .= '<button type="button" class="button-link nag-terminator-all">' . $all_label . '</button>';
        }
        $html .= '<span class="nag-terminator-confirm" hidden>';
        $html .= esc_html__( 'Terminate for all admins?', 'wp-nag-terminator' );
        $html .= ' <button type="button" class="button-link nag-terminator-all-yes">' . esc_html__( 'Yes', 'wp-nag-terminator' ) . '</button>';
        $html .= ' <button type="button" class="button-link nag-terminator-all-no">' . esc_html__( 'Cancel', 'wp-nag-terminator' ) . '</button>';
        $html .= '</span>';
        $html .= '</div>';

        return $html;
    }

    /**
     * Inject data-nag-id attribute into the first opening div tag.
     *
     * @param string $html   HTML.
     * @param string $nag_id NAG ID.
     * @return string
     */
    private function inject_data_attr( $html, $nag_id ) {
        $attr = ' data-nag-id="' . esc_attr( $nag_id ) . '"';
        return preg_replace( '#(<div\b[^>]*?)>#i', '$1' . $attr . '>', $html, 1 );
    }

    /**
     * Determine whether the notice HTML has a WP dismiss button.
     *
     * @param string $html Notice HTML.
     * @return bool
     */
    private function is_dismissable_html( $html ) {
        return (bool) preg_match( '/button[\s\S]*?notice-dismiss|dismiss[\s\S]*?button/i', $html );
    }

    /**
     * Build a short excerpt for display.
     *
     * @param string $html Notice HTML.
     * @return string
     */
    private function make_excerpt( $html ) {
        $text = wp_strip_all_tags( $html );
        $text = trim( preg_replace( '/\s+/', ' ', $text ) );
        if ( strlen( $text ) > 200 ) {
            $text = substr( $text, 0, 200 ) . '...';
        }
        return $text;
    }

    /**
     * Try to guess a human-readable source from the notice HTML.
     *
     * @param string $html Notice HTML.
     * @return string
     */
    private function guess_source_label( $html ) {
        $candidates = array(
            'wc-admin'       => 'WooCommerce',
            'woocommerce'    => 'WooCommerce',
            'wp-seo'         => 'Yoast SEO',
            'wordpress-seo'  => 'Yoast SEO',
            'elementor'      => 'Elementor',
            'health-check'   => 'Site Health',
            'update-php'     => 'PHP Update',
            'update-core'    => 'WordPress Core Update',
            'wordfence'      => 'Wordfence',
            'akismet'        => 'Akismet',
            'jetpack'        => 'Jetpack',
            'ithemes'        => 'iThemes',
            'wp-rocket'      => 'WP Rocket',
        );

        $haystack = strtolower( $html );
        foreach ( $candidates as $needle => $label ) {
            if ( false !== strpos( $haystack, $needle ) ) {
                return $label;
            }
        }
        return __( 'Unknown source', 'wp-nag-terminator' );
    }

    /**
     * Compute the NAG ID from notice HTML + source.
     *
     * @param string $html   Notice HTML.
     * @param string $source Source label.
     * @return string
     */
    public function fingerprint( $html, $source = '' ) {
        $normalized = $this->normalize_text( $html );
        $payload    = 'source=' . $source . '|text=' . $normalized;
        return 'nag_' . substr( sha1( $payload ), 0, 10 );
    }

    /**
     * Normalize notice text for stable fingerprinting.
     *
     * @param string $html Notice HTML.
     * @return string
     */
    private function normalize_text( $html ) {
        // Remove inline scripts/styles.
        $html = preg_replace( '#<script\b[^>]*>.*?</script>#is', '', $html );
        $html = preg_replace( '#<style\b[^>]*>.*?</style>#is', '', $html );
        $text = wp_strip_all_tags( $html );
        $text = strtolower( $text );
        $text = preg_replace( '/\s+/', ' ', $text );
        $text = trim( $text );
        // Replace common volatile tokens.
        $text = preg_replace( '/\b\d{1,3}(\.\d+){0,3}\b/', '__VER__', $text ); // version-like
        $text = preg_replace( '/\b\d{4}-\d{2}-\d{2}\b/', '__DATE__', $text );
        $text = preg_replace( '/https?:\/\/\S+/', '__URL__', $text );
        // Truncate for speed.
        if ( strlen( $text ) > 500 ) {
            $text = substr( $text, 0, 500 );
        }
        return $text;
    }
}

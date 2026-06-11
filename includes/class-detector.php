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
     *
     * The buffer starts at admin_head (priority 1) so it covers the body
     * of every admin page. It closes on shutdown at the lowest possible
     * priority so we still catch notices rendered late (e.g. some plugins
     * print notices from admin_footer or admin_footer-{hook_suffix} hooks
     * with default priority, or from admin_print_footer_scripts).
     */
    public function register() {
        add_action( 'admin_head', array( $this, 'start_buffer' ), 1 );
        add_action( 'in_admin_header', array( $this, 'start_buffer' ), 1 );
        add_action( 'shutdown', array( $this, 'end_buffer' ), -PHP_INT_MAX );
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
        if ( defined( 'DOING_AJAX' ) && DOING_AJAX ) {
            return;
        }
        if ( ! Capabilities::can_dismiss_for_user() ) {
            return;
        }
        $this->buffering = true;
        // No callback: process_buffer is called manually in end_buffer
        // because we capture the content with ob_get_clean() and echo
        // the processed result. This is what lets us hold the buffer
        // open all the way until shutdown (so notices rendered on
        // admin_footer / admin_print_footer_scripts / shutdown are
        // still inside it).
        ob_start();
    }

    /**
     * End the output buffer at shutdown and process the captured
     * content.
     *
     * We use ob_get_clean() to pull the buffered content out as a
     * string, run process_buffer() on it (strip dismissed, inject
     * data attributes + action bar on the rest), then echo the
     * result back to the client. Because this runs at shutdown
     * priority -PHP_INT_MAX, every admin-page hook has already
     * fired and any notice rendered anywhere in the page is in
     * the buffer.
     */
    public function end_buffer() {
        if ( ! $this->buffering ) {
            return;
        }
        $this->buffering = false;

        if ( ob_get_level() <= 0 ) {
            return;
        }

        $html = ob_get_clean();
        if ( false === $html || '' === $html ) {
            return;
        }

        $processed = $this->process_buffer( $html );
        echo $processed; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
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

        // Pull out any notice HTML that lives inside our Log table on the
        // Tools -> NAG Terminator admin page. We want to keep that HTML
        // exactly as-is (it was already rendered with the original notice
        // content + our own data-nag-id attribute). The rest of the page
        // is processed normally so dismissed NAGs are still hidden here.
        $placeholders = array();
        $i            = 0;
        $html         = preg_replace_callback(
            '#<div\b[^>]*class\s*=\s*["\'][^"\']*\bwp-nag-terminator-log-content\b[^"\']*["\'][^>]*>.*?</div>\s*</div>#is',
            function ( $m ) use ( &$placeholders, &$i ) {
                $key                     = "\x00NAG_LOG_PLACEHOLDER_{$i}\x00";
                $placeholders[ $key ]    = $m[0];
                $i++;
                return $key;
            },
            $html
        );

        $hidden_ids = Storage::get_hidden_ids_for_user();
        $can_global = Capabilities::can_dismiss_global();

        // Find every <div ... class="notice ..."> block (incl. notice-error,
        // notice-warning, notice-info, notice-success and their sub-classes).
        $pattern = '#<div\b[^>]*class\s*=\s*["\'][^"\']*\bnotice\b[^"\']*["\'][^>]*>.*?</div>#is';

        $result = preg_replace_callback(
            $pattern,
            function ( $matches ) use ( $hidden_ids, $can_global ) {
                $notice_html = $matches[0];

                $excerpt    = $this->make_excerpt( $notice_html );
                $source     = $this->guess_source_label( $notice_html );
                list( $nag_id, $prefix_fp ) = $this->resolve_fingerprints( $notice_html, $source );

                // Prefix-fallback: if the full nag_id isn't in the hidden
                // list, look for any previously-dismissed NAG (user or
                // global) with the same prefix fingerprint. We don't
                // filter by source because the source label can drift
                // (e.g. WP core renders the same update notice with
                // different inner links for admins vs other roles,
                // which trips our guess_source_label heuristic). The
                // 30-char prefix is specific enough to identify the
                // NAG without over-matching.
                if ( ! in_array( $nag_id, $hidden_ids, true ) && '' !== $prefix_fp ) {
                    $matched = Storage::find_dismissed_by_prefix_any( $prefix_fp );
                    if ( $matched ) {
                        $nag_id = $matched;
                    }
                }

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
            $result = $html;
        }

        // Put the log-content blocks back where we masked them.
        if ( ! empty( $placeholders ) ) {
            $result = strtr( $result, $placeholders );
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
        $me_label  = esc_html__( 'Hide for me', 'wp-nag-terminator' );
        $all_label = esc_html__( 'Hide for everyone', 'wp-nag-terminator' );

        $html  = '<div class="nag-terminator-actions" data-nag-id="' . esc_attr( $nag_id ) . '">';
        $html .= '<button type="button" class="button-link nag-terminator-me">' . $me_label . '</button>';

        if ( $can_global ) {
            $html .= '<span class="nag-terminator-sep">|</span>';
            $html .= '<button type="button" class="button-link nag-terminator-all">' . $all_label . '</button>';
        }
        $html .= '<span class="nag-terminator-confirm" hidden>';
        $html .= esc_html__( 'Hide for all admins?', 'wp-nag-terminator' );
        $html .= ' <button type="button" class="button-link nag-terminator-all-yes">' . esc_html__( 'Yes', 'wp-nag-terminator' ) . '</button>';
        $html .= ' <button type="button" class="button-link nag-terminator-all-no">' . esc_html__( 'Cancel', 'wp-nag-terminator' ) . '</button>';
        $html .= '</span>';
        $html .= '<a href="#" class="nag-terminator-help" role="button" aria-label="' . esc_attr__( 'What does this do?', 'wp-nag-terminator' ) . '">?</a>';
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
     * Compute a "prefix" fingerprint from the first N characters of the
     * normalized text. Notices that differ in only the trailing text
     * (e.g. role-conditional copy at the end) will share this prefix.
     *
     * This is used to bridge the per-user fingerprint drift bug where
     * WP core renders the same update notice with different text for
     * admins vs non-admins ("update now" vs "notify the site admin").
     *
     * The prefix is intentionally short (30 chars) to capture just the
     * "core" announcement — e.g. "WordPress 7.0 is available!" —
     * which is the stable part across role-conditional renderings.
     *
     * Uses djb2 hash (matches the JS implementation in admin.js so
     * client and server compute the same fingerprint).
     *
     * @param string $html Notice HTML.
     * @return string
     */
    public function prefix_fingerprint( $html ) {
        $normalized = $this->normalize_text( $html );
        $prefix     = substr( $normalized, 0, 30 );
        // djb2 hash, base36-encoded (no zero-padding, mirrors the JS
        // implementation in admin.js).
        $h = 5381;
        $len = strlen( $prefix );
        for ( $i = 0; $i < $len; $i++ ) {
            $h = ( ( ( $h << 5 ) + $h ) + ord( $prefix[ $i ] ) ) & 0xFFFFFFFF;
        }
        $base36 = base_convert( (string) $h, 10, 36 );
        return 'nagp_' . substr( $base36, 0, 10 );
    }

    /**
     * Resolve a notice to a canonical nag_id, considering both the
     * full fingerprint and the prefix fingerprint. If the current
     * notice's prefix matches a stored (dismissed) prefix within
     * the same source, the dismissed nag_id is returned so the
     * dismissal carries across role-conditional text variations.
     *
     * @param string $html   Notice HTML.
     * @param string $source Source label.
     * @return array{0: string, 1: string} [nag_id, prefix_fingerprint]
     */
    public function resolve_fingerprints( $html, $source = '' ) {
        $nag_id = $this->fingerprint( $html, $source );
        $prefix = $this->prefix_fingerprint( $html );
        return array( $nag_id, $prefix );
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

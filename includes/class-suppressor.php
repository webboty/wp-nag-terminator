<?php
/**
 * Suppressor: belt-and-braces removal of dismissed notices.
 *
 * The Detector's output buffer already strips dismissed notices on render.
 * This class provides an additional late-priority hook for cases where
 * notices are rendered outside the buffered region (e.g., some plugins
 * echo notices in admin_init or directly from actions attached to
 * late hooks).
 *
 * @package WpNagTerminator
 */

namespace WpNagTerminator;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Suppressor {

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
        // Most suppression happens in Detector::process_buffer().
        // Hook here reserved for v2 source-level blocking.
        add_filter( 'wp_admin_notice_args', array( $this, 'filter_admin_notice_args' ), 10, 2 );
    }

    /**
     * Filter for the new WP 6.4+ admin_notice_* API.
     *
     * The hook exposes a unique-ish $id as the 2nd argument. We strip
     * matching ones by id; otherwise we let the WP layer call them.
     *
     * @param array  $args Notice args.
     * @param string $id   Notice unique id (when provided).
     * @return array
     */
    public function filter_admin_notice_args( $args, $id = '' ) {
        if ( '' === $id ) {
            return $args;
        }
        $hidden = Storage::get_hidden_ids_for_user();
        $nag_id = 'nag_' . substr( sha1( 'id=' . $id ), 0, 10 );
        if ( in_array( $nag_id, $hidden, true ) ) {
            // Returning an empty array suppresses the notice in WP 6.4+.
            return array();
        }
        return $args;
    }
}

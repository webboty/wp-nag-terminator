<?php
/**
 * Storage layer: per-user, global, and archive CRUD.
 *
 * @package WpNagTerminator
 */

namespace WpNagTerminator;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Storage {

    const META_USER        = 'wp_nag_terminator_dismissed';
    const OPTION_GLOBAL    = 'wp_nag_terminator_global_dismissed';
    const OPTION_ARCHIVE   = 'wp_nag_terminator_archive';
    const ARCHIVE_MAX      = 500;

    /* ---------- Generic option accessors ---------- */

    /**
     * Get a map of nag_id => record.
     *
     * @param string $option Option name.
     * @return array
     */
    private static function get_map( $option ) {
        $value = get_option( $option, array() );
        return is_array( $value ) ? $value : array();
    }

    /**
     * Persist a map.
     *
     * @param string $option Option name.
     * @param array  $map    Map to save.
     * @return bool
     */
    private static function save_map( $option, array $map ) {
        return update_option( $option, $map, false );
    }

    /* ---------- Per-user ---------- */

    /**
     * Get all per-user dismissed NAGs.
     *
     * @param int $user_id User ID.
     * @return array
     */
    public static function get_user_dismissed( $user_id ) {
        $user_id = (int) $user_id;
        if ( $user_id <= 0 ) {
            return array();
        }
        $value = get_user_meta( $user_id, self::META_USER, true );
        return is_array( $value ) ? $value : array();
    }

    /**
     * Has the user dismissed this NAG?
     *
     * @param int    $user_id User ID.
     * @param string $nag_id  NAG ID.
     * @return bool
     */
    public static function is_user_dismissed( $user_id, $nag_id ) {
        $map = self::get_user_dismissed( $user_id );
        return isset( $map[ $nag_id ] );
    }

    /**
     * Dismiss a NAG for a user.
     *
     * @param int    $user_id User ID.
     * @param string $nag_id  NAG ID.
     * @param array  $meta    Extra metadata (excerpt, source).
     * @return bool
     */
    public static function dismiss_for_user( $user_id, $nag_id, array $meta = array() ) {
        $user_id = (int) $user_id;
        $nag_id  = (string) $nag_id;
        if ( $user_id <= 0 || '' === $nag_id ) {
            return false;
        }
        $map = self::get_user_dismissed( $user_id );
        if ( isset( $map[ $nag_id ] ) ) {
            return true;
        }
        $map[ $nag_id ] = array(
            'source'    => isset( $meta['source'] ) ? sanitize_key( $meta['source'] ) : '',
            'excerpt'   => isset( $meta['excerpt'] ) ? wp_kses_post( $meta['excerpt'] ) : '',
            'dismissed' => time(),
        );
        $ok = (bool) update_user_meta( $user_id, self::META_USER, $map );
        if ( $ok ) {
            self::invalidate_user_count_cache( $user_id );
        }
        return $ok;
    }

    /**
     * Restore (un-dismiss) a NAG for a user.
     *
     * @param int    $user_id User ID.
     * @param string $nag_id  NAG ID.
     * @return bool
     */
    public static function restore_for_user( $user_id, $nag_id ) {
        $user_id = (int) $user_id;
        $nag_id  = (string) $nag_id;
        if ( $user_id <= 0 || '' === $nag_id ) {
            return false;
        }
        $map = self::get_user_dismissed( $user_id );
        if ( ! isset( $map[ $nag_id ] ) ) {
            return true;
        }
        unset( $map[ $nag_id ] );
        $ok = (bool) update_user_meta( $user_id, self::META_USER, $map );
        if ( $ok ) {
            self::invalidate_user_count_cache( $user_id );
        }
        return $ok;
    }

    /* ---------- Global (everyone) ---------- */

    /**
     * Get globally dismissed NAGs.
     *
     * @return array
     */
    public static function get_global_dismissed() {
        return self::get_map( self::OPTION_GLOBAL );
    }

    /**
     * Is a NAG globally dismissed?
     *
     * @param string $nag_id NAG ID.
     * @return bool
     */
    public static function is_global_dismissed( $nag_id ) {
        $map = self::get_global_dismissed();
        return isset( $map[ $nag_id ] );
    }

    /**
     * Dismiss a NAG globally.
     *
     * @param string $nag_id  NAG ID.
     * @param int    $user_id User ID who initiated.
     * @param array  $meta    Extra metadata.
     * @return bool
     */
    public static function dismiss_global( $nag_id, $user_id, array $meta = array() ) {
        $nag_id  = (string) $nag_id;
        $user_id = (int) $user_id;
        if ( '' === $nag_id ) {
            return false;
        }
        $map = self::get_global_dismissed();
        if ( isset( $map[ $nag_id ] ) ) {
            return true;
        }
        $map[ $nag_id ] = array(
            'source'    => isset( $meta['source'] ) ? sanitize_key( $meta['source'] ) : '',
            'excerpt'   => isset( $meta['excerpt'] ) ? wp_kses_post( $meta['excerpt'] ) : '',
            'dismissed' => time(),
        );
        $ok = self::save_map( self::OPTION_GLOBAL, $map );

        if ( $ok ) {
            self::archive( $nag_id, $user_id, 'global', $meta );
            self::invalidate_all_count_caches();
        }
        return $ok;
    }

    /**
     * Restore a globally-dismissed NAG.
     *
     * @param string $nag_id NAG ID.
     * @return bool
     */
    public static function restore_global( $nag_id ) {
        $nag_id = (string) $nag_id;
        if ( '' === $nag_id ) {
            return false;
        }
        $map = self::get_global_dismissed();
        if ( ! isset( $map[ $nag_id ] ) ) {
            return true;
        }
        unset( $map[ $nag_id ] );
        $ok = self::save_map( self::OPTION_GLOBAL, $map );
        if ( $ok ) {
            self::invalidate_all_count_caches();
        }
        return $ok;
    }

    /* ---------- Archive (recycle bin) ---------- */

    /**
     * Get the full archive.
     *
     * @return array
     */
    public static function get_archive() {
        return self::get_map( self::OPTION_ARCHIVE );
    }

    /**
     * Add or update an archive entry.
     *
     * @param string $nag_id  NAG ID.
     * @param int    $user_id User ID who initiated.
     * @param string $scope   'user' | 'global'.
     * @param array  $meta    Metadata: content, source_hook, source_label, excerpt.
     * @return bool
     */
    public static function archive( $nag_id, $user_id, $scope = 'global', array $meta = array() ) {
        $nag_id  = (string) $nag_id;
        $user_id = (int) $user_id;
        $scope   = ( 'user' === $scope ) ? 'user' : 'global';

        if ( '' === $nag_id ) {
            return false;
        }
        $archive = self::get_archive();

        $content = isset( $meta['content'] ) ? Ajax::sanitize_notice_html( $meta['content'] ) : '';
        $excerpt = isset( $meta['excerpt'] ) ? wp_kses_post( $meta['excerpt'] ) : wp_trim_words( wp_strip_all_tags( $content ), 30, '...' );
        $source  = isset( $meta['source_hook'] ) ? sanitize_key( $meta['source_hook'] ) : '';
        $label   = isset( $meta['source_label'] ) ? sanitize_text_field( $meta['source_label'] ) : '';

        $existing = isset( $archive[ $nag_id ] ) ? $archive[ $nag_id ] : array();

        $archive[ $nag_id ] = array_merge(
            $existing,
            array(
                'source'        => $source,
                'source_label'  => $label,
                'excerpt'       => $excerpt,
                'content'       => $content,
                'first_seen'    => isset( $existing['first_seen'] ) ? (int) $existing['first_seen'] : time(),
                'terminated_by' => $user_id,
                'terminated_at' => time(),
                'scope'         => $scope,
            )
        );

        // Enforce cap.
        if ( count( $archive ) > self::ARCHIVE_MAX ) {
            uasort(
                $archive,
                function ( $a, $b ) {
                    $at = isset( $a['terminated_at'] ) ? (int) $a['terminated_at'] : 0;
                    $bt = isset( $b['terminated_at'] ) ? (int) $b['terminated_at'] : 0;
                    return $at <=> $bt;
                }
            );
            $archive = array_slice( $archive, -self::ARCHIVE_MAX, null, true );
        }

        return self::save_map( self::OPTION_ARCHIVE, $archive );
    }

    /**
     * Get a single archive entry.
     *
     * @param string $nag_id NAG ID.
     * @return array|null
     */
    public static function get_archive_entry( $nag_id ) {
        $archive = self::get_archive();
        return isset( $archive[ $nag_id ] ) ? $archive[ $nag_id ] : null;
    }

    /**
     * Permanently delete an archive entry.
     *
     * @param string $nag_id NAG ID.
     * @return bool
     */
    public static function delete_archive_entry( $nag_id ) {
        $nag_id = (string) $nag_id;
        $archive = self::get_archive();
        if ( ! isset( $archive[ $nag_id ] ) ) {
            return true;
        }
        unset( $archive[ $nag_id ] );
        return self::save_map( self::OPTION_ARCHIVE, $archive );
    }

    /**
     * Purge archive entries older than $max_age seconds.
     *
     * @param int $max_age_seconds Age threshold in seconds.
     * @return int Number of entries purged.
     */
    public static function purge_older_than( $max_age_seconds ) {
        $max_age_seconds = (int) $max_age_seconds;
        if ( $max_age_seconds <= 0 ) {
            return 0;
        }
        $cutoff = time() - $max_age_seconds;
        $archive = self::get_archive();
        $purged  = 0;
        foreach ( $archive as $id => $entry ) {
            $ts = isset( $entry['terminated_at'] ) ? (int) $entry['terminated_at'] : 0;
            if ( $ts > 0 && $ts < $cutoff ) {
                unset( $archive[ $id ] );
                $purged++;
            }
        }
        if ( $purged > 0 ) {
            self::save_map( self::OPTION_ARCHIVE, $archive );
        }
        return $purged;
    }

    /* ---------- Helpers for the current user ---------- */

    /**
     * Get all NAG IDs that should be hidden for the current user.
     *
     * @param int|null $user_id Defaults to current.
     * @return array
     */
    public static function get_hidden_ids_for_user( $user_id = null ) {
        if ( null === $user_id ) {
            $user = wp_get_current_user();
            $user_id = $user ? (int) $user->ID : 0;
        }
        $ids = array();
        if ( $user_id > 0 ) {
            $ids = array_merge( $ids, array_keys( self::get_user_dismissed( $user_id ) ) );
        }
        if ( ! Capabilities::should_bypass_global( $user_id ) ) {
            $ids = array_merge( $ids, array_keys( self::get_global_dismissed() ) );
        }
        return array_values( array_unique( array_filter( $ids ) ) );
    }

    /* ---------- Cached counts for the admin bar ---------- */

    const COUNT_CACHE_KEY = 'wp_nag_terminator_count_';
    const GLOBAL_VERSION_OPTION = 'wp_nag_terminator_global_version';
    const COUNT_CACHE_TTL = 300; // 5 minutes

    /**
     * Get the total hidden-NAG count for a user (user + global).
     * Cached in a per-user transient. The cache is keyed on a
     * global version so a change to the global list automatically
     * invalidates all users' caches.
     *
     * @param int $user_id User ID.
     * @return int
     */
    public static function get_total_hidden_count( $user_id ) {
        $user_id = (int) $user_id;
        if ( $user_id <= 0 ) {
            return 0;
        }
        $version = (int) get_option( self::GLOBAL_VERSION_OPTION, 1 );
        $cache_key = self::COUNT_CACHE_KEY . $user_id . '_v' . $version;
        $cached    = get_transient( $cache_key );
        if ( false !== $cached && is_numeric( $cached ) ) {
            return (int) $cached;
        }
        $count = count( self::get_user_dismissed( $user_id ) )
               + count( self::get_global_dismissed() );
        set_transient( $cache_key, $count, self::COUNT_CACHE_TTL );
        return $count;
    }

    /**
     * Bump the global version, invalidating all per-user count caches.
     * Call this whenever the global dismissed list or the archive
     * changes (dismiss_global, restore_global, delete_archive_entry,
     * archive).
     */
    public static function invalidate_all_count_caches() {
        $version = (int) get_option( self::GLOBAL_VERSION_OPTION, 1 );
        update_option( self::GLOBAL_VERSION_OPTION, $version + 1, false );
    }

    /**
     * Invalidate a single user's count cache.
     * Call this when that user's per-user dismissed list changes.
     *
     * @param int $user_id User ID.
     */
    public static function invalidate_user_count_cache( $user_id ) {
        $version = (int) get_option( self::GLOBAL_VERSION_OPTION, 1 );
        delete_transient( self::COUNT_CACHE_KEY . (int) $user_id . '_v' . $version );
    }
}

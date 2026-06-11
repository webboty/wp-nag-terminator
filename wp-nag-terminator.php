<?php
/**
 * Plugin Name:       NAG Terminator
 * Plugin URI:        https://example.com/plugins/wp-nag-terminator
 * Description:       Hide (terminate) WordPress admin notice NAGs for yourself or for everyone, with full restore history.
 * Version:           1.0.0
 * Requires at least: 5.5
 * Requires PHP:      7.4
 * Tested up to:      6.5
 * Author:            Tony Hartmann
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       wp-nag-terminator
 * Domain Path:       /languages
 *
 * @package WpNagTerminator
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

define( 'WP_NAG_TERMINATOR_VERSION', '1.0.0' );
define( 'WP_NAG_TERMINATOR_FILE', __FILE__ );
define( 'WP_NAG_TERMINATOR_DIR', plugin_dir_path( __FILE__ ) );
define( 'WP_NAG_TERMINATOR_URL', plugin_dir_url( __FILE__ ) );
define( 'WP_NAG_TERMINATOR_BASENAME', plugin_basename( __FILE__ ) );
define( 'WP_NAG_TERMINATOR_SLUG', 'wp-nag-terminator' );

require_once WP_NAG_TERMINATOR_DIR . 'includes/class-plugin.php';
require_once WP_NAG_TERMINATOR_DIR . 'includes/class-installer.php';
require_once WP_NAG_TERMINATOR_DIR . 'includes/class-capabilities.php';
require_once WP_NAG_TERMINATOR_DIR . 'includes/class-storage.php';
require_once WP_NAG_TERMINATOR_DIR . 'includes/class-detector.php';
require_once WP_NAG_TERMINATOR_DIR . 'includes/class-suppressor.php';
require_once WP_NAG_TERMINATOR_DIR . 'includes/class-ajax.php';
require_once WP_NAG_TERMINATOR_DIR . 'includes/class-assets.php';
require_once WP_NAG_TERMINATOR_DIR . 'includes/class-admin-page.php';

register_activation_hook( __FILE__, array( 'WpNagTerminator\\Installer', 'activate' ) );
register_deactivation_hook( __FILE__, array( 'WpNagTerminator\\Installer', 'deactivate' ) );

add_action( 'plugins_loaded', array( 'WpNagTerminator\\Plugin', 'instance' ) );

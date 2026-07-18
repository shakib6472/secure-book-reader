<?php
/**
 * Plugin Name:       Secure Book Reader By Shakib Shown
 * Plugin URI:        https://github.com/shakib6472/secure-book-reader
 * Description:       Lets customers read purchased PDF books in a secure in-browser reader. Requires WooCommerce.
 * Version:           1.0.0
 * Requires at least: 6.0
 * Requires PHP:      8.1
 * Author:            Shakib Shown
 * Author URI:        https://shakib6472.com
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       secure-book-reader
 * License:           GPL-2.0-or-later
 */

// Block direct access to this file.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'SBR_VERSION', '1.0.0' );
define( 'SBR_PLUGIN_FILE', __FILE__ );
define( 'SBR_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'SBR_PLUGIN_URL', plugin_dir_url( __FILE__ ) );

require_once SBR_PLUGIN_DIR . 'includes/class-sbr-activator.php';
require_once SBR_PLUGIN_DIR . 'includes/class-sbr-plugin.php';

register_activation_hook( __FILE__, array( 'SBR_Activator', 'activate' ) );
register_deactivation_hook( __FILE__, array( 'SBR_Activator', 'deactivate' ) );

/**
 * Boot the plugin after all plugins are loaded, so we can detect WooCommerce.
 */
function sbr_init() {
	SBR_Plugin::instance();
}
add_action( 'plugins_loaded', 'sbr_init' );

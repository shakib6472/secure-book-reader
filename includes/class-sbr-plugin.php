<?php
/**
 * Main plugin class: loads all modules and checks dependencies.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class SBR_Plugin {

	/**
	 * @var SBR_Plugin|null
	 */
	private static $instance = null;

	/**
	 * @return SBR_Plugin
	 */
	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	private function __construct() {
		if ( ! $this->is_woocommerce_active() ) {
			add_action( 'admin_notices', array( $this, 'woocommerce_missing_notice' ) );
			return;
		}

		$this->load_modules();
	}

	/**
	 * Whether WooCommerce is loaded.
	 */
	private function is_woocommerce_active() {
		return class_exists( 'WooCommerce' );
	}

	/**
	 * Admin notice shown when WooCommerce is not active.
	 */
	public function woocommerce_missing_notice() {
		echo '<div class="notice notice-error"><p>';
		echo esc_html__( 'Secure Book Reader requires WooCommerce to be installed and active.', 'secure-book-reader' );
		echo '</p></div>';
	}

	/**
	 * Loads plugin modules. Modules are added phase by phase:
	 * admin (Phase 2), secure endpoint (Phase 3), frontend reader (Phase 4+).
	 */
	private function load_modules() {
		// Loaded on every request: admin-ajax also serves customer PDF requests,
		// and the metabox class holds the shared meta key constants.
		require_once SBR_PLUGIN_DIR . 'includes/admin/class-sbr-product-metabox.php';
		require_once SBR_PLUGIN_DIR . 'includes/class-sbr-access.php';
		require_once SBR_PLUGIN_DIR . 'includes/class-sbr-endpoint.php';

		SBR_Endpoint::init();

		if ( is_admin() ) {
			SBR_Product_Metabox::init();
		}
	}
}

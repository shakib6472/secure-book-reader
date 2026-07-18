<?php
/**
 * Activation / deactivation logic for Secure Book Reader.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class SBR_Activator {

	/**
	 * Option name that stores the secure storage directory name (random suffix).
	 */
	const OPTION_SECURE_DIR = 'sbr_secure_dir_name';

	/**
	 * Runs on plugin activation.
	 */
	public static function activate() {
		if ( version_compare( PHP_VERSION, '8.1', '<' ) ) {
			deactivate_plugins( plugin_basename( SBR_PLUGIN_FILE ) );
			wp_die(
				esc_html__( 'Secure Book Reader requires PHP 8.1 or higher.', 'secure-book-reader' ),
				esc_html__( 'Plugin activation error', 'secure-book-reader' ),
				array( 'back_link' => true )
			);
		}

		self::create_secure_storage();
		update_option( 'sbr_version', SBR_VERSION );
	}

	/**
	 * Runs on plugin deactivation. Keeps all data and files intact.
	 */
	public static function deactivate() {
		// Intentionally empty: uploaded books and settings must survive deactivation.
	}

	/**
	 * Creates the protected directory where book PDFs will be stored,
	 * e.g. wp-content/uploads/sbr-books-a1b2c3d4/ with direct access blocked.
	 */
	public static function create_secure_storage() {
		$dir_name = get_option( self::OPTION_SECURE_DIR );

		if ( ! $dir_name ) {
			$dir_name = 'sbr-books-' . wp_generate_password( 12, false, false );
			update_option( self::OPTION_SECURE_DIR, $dir_name );
		}

		$path = self::get_secure_dir_path();

		if ( ! file_exists( $path ) ) {
			wp_mkdir_p( $path );
		}

		self::write_protection_files( $path );
	}

	/**
	 * Absolute filesystem path of the secure storage directory.
	 */
	public static function get_secure_dir_path() {
		$uploads  = wp_upload_dir();
		$dir_name = get_option( self::OPTION_SECURE_DIR );

		return trailingslashit( $uploads['basedir'] ) . $dir_name;
	}

	/**
	 * Drops .htaccess and index.php blockers into the secure directory
	 * so PDFs can never be fetched via a direct URL (Apache/LiteSpeed).
	 */
	private static function write_protection_files( $path ) {
		$htaccess = trailingslashit( $path ) . '.htaccess';
		if ( ! file_exists( $htaccess ) ) {
			$rules  = "# Secure Book Reader: deny all direct access\n";
			$rules .= "<IfModule mod_authz_core.c>\n";
			$rules .= "Require all denied\n";
			$rules .= "</IfModule>\n";
			$rules .= "<IfModule !mod_authz_core.c>\n";
			$rules .= "Order deny,allow\n";
			$rules .= "Deny from all\n";
			$rules .= "</IfModule>\n";
			file_put_contents( $htaccess, $rules );
		}

		$index = trailingslashit( $path ) . 'index.php';
		if ( ! file_exists( $index ) ) {
			file_put_contents( $index, "<?php\n// Silence is golden.\n" );
		}
	}
}

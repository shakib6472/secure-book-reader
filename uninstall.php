<?php
/**
 * Runs when the plugin is deleted from the WordPress admin.
 * Removes plugin options but intentionally keeps uploaded book PDFs,
 * so accidental deletion of the plugin does not destroy purchased content.
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

delete_option( 'sbr_version' );
// Note: 'sbr_secure_dir_name' is kept on purpose so the PDF folder
// can be found again if the plugin is reinstalled.

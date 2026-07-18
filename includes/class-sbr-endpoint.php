<?php
/**
 * Customer-facing secure endpoint that streams a book PDF.
 *
 * Every request must pass three checks, in order:
 *   1. the user is logged in,
 *   2. the nonce is valid,
 *   3. the user has bought this specific book (SBR_Access).
 *
 * The PDF never has a public URL; this endpoint is the only way to fetch it.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class SBR_Endpoint {

	/**
	 * Nonce action prefix; the book ID is appended so a nonce for one
	 * book cannot be reused for another.
	 */
	const NONCE_PREFIX = 'sbr_read_book_';

	/**
	 * Registers AJAX hooks.
	 */
	public static function init() {
		$self = new self();

		add_action( 'wp_ajax_sbr_get_book', array( $self, 'serve_book' ) );
		add_action( 'wp_ajax_nopriv_sbr_get_book', array( $self, 'deny_logged_out' ) );

		// Temporary diagnostic endpoint for Phase 3 testing; removed once the
		// reader UI (Phase 4) takes over nonce provisioning.
		add_action( 'wp_ajax_sbr_test_access', array( $self, 'test_access' ) );
		add_action( 'wp_ajax_nopriv_sbr_test_access', array( $self, 'test_access_logged_out' ) );
	}

	/**
	 * Builds the secure stream URL (with a fresh nonce) for a book.
	 * The reader page will use this to feed PDF.js.
	 */
	public static function get_stream_url( $book_id ) {
		$book_id = absint( $book_id );

		return add_query_arg(
			array(
				'action'  => 'sbr_get_book',
				'book_id' => $book_id,
				'nonce'   => wp_create_nonce( self::NONCE_PREFIX . $book_id ),
			),
			admin_url( 'admin-ajax.php' )
		);
	}

	/**
	 * Streams the PDF after all access checks pass.
	 */
	public function serve_book() {
		$book_id = isset( $_GET['book_id'] ) ? absint( $_GET['book_id'] ) : 0;
		$nonce   = isset( $_GET['nonce'] ) ? sanitize_key( $_GET['nonce'] ) : '';

		if ( ! $book_id || ! wp_verify_nonce( $nonce, self::NONCE_PREFIX . $book_id ) ) {
			wp_die( esc_html__( 'Invalid or expired request. Please reopen the reader.', 'secure-book-reader' ), '', array( 'response' => 403 ) );
		}

		if ( ! SBR_Access::can_read( get_current_user_id(), $book_id ) ) {
			wp_die( esc_html__( 'You do not have access to this book. Only customers who purchased it can read it.', 'secure-book-reader' ), '', array( 'response' => 403 ) );
		}

		$file = get_post_meta( $book_id, SBR_Product_Metabox::META_PDF_FILE, true );
		$path = $file ? trailingslashit( SBR_Activator::get_secure_dir_path() ) . basename( $file ) : '';

		if ( ! $file || ! is_readable( $path ) ) {
			wp_die( esc_html__( 'This book is not available yet.', 'secure-book-reader' ), '', array( 'response' => 404 ) );
		}

		self::stream_pdf( $path );
	}

	/**
	 * Logged-out users are always denied.
	 */
	public function deny_logged_out() {
		wp_die( esc_html__( 'Please log in to read your books.', 'secure-book-reader' ), '', array( 'response' => 401 ) );
	}

	/**
	 * Sends the PDF bytes with no-cache headers and stops execution.
	 */
	public static function stream_pdf( $path ) {
		nocache_headers();
		header( 'Content-Type: application/pdf' );
		header( 'Content-Length: ' . filesize( $path ) );
		header( 'Content-Disposition: inline; filename="book.pdf"' );
		header( 'X-Content-Type-Options: nosniff' );

		while ( ob_get_level() ) {
			ob_end_clean();
		}

		readfile( $path );
		exit;
	}

	/**
	 * TEMPORARY (Phase 3 testing): reports the current user's access to a book
	 * as JSON, including a ready-made stream URL when access is granted.
	 */
	public function test_access() {
		$book_id = isset( $_GET['book_id'] ) ? absint( $_GET['book_id'] ) : 0;
		$user_id = get_current_user_id();
		$allowed = $book_id ? SBR_Access::can_read( $user_id, $book_id ) : false;

		$data = array(
			'loggedIn' => true,
			'userId'   => $user_id,
			'bookId'   => $book_id,
			'canRead'  => $allowed,
		);

		if ( $allowed ) {
			$data['streamUrl'] = self::get_stream_url( $book_id );

			// Convenience: &open=1 redirects straight to the PDF stream,
			// avoiding copy-paste errors with JSON-escaped URLs.
			if ( ! empty( $_GET['open'] ) ) {
				wp_safe_redirect( $data['streamUrl'] );
				exit;
			}
		}

		wp_send_json( $data, null, JSON_UNESCAPED_SLASHES );
	}

	/**
	 * TEMPORARY (Phase 3 testing): logged-out variant of the diagnostic.
	 */
	public function test_access_logged_out() {
		wp_send_json(
			array(
				'loggedIn' => false,
				'canRead'  => false,
			),
			401
		);
	}
}

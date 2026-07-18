<?php
/**
 * Frontend reader: the /read-book/ URL, the full-screen reader template,
 * and the "Read Now" button (product page hook + shortcode).
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class SBR_Reader {

	/**
	 * Data handed to templates/reader.php.
	 *
	 * @var array
	 */
	public static $template_data = array();

	/**
	 * Registers all frontend hooks.
	 */
	public static function init() {
		$self = new self();

		add_action( 'init', array( $self, 'register_rewrite' ) );
		add_filter( 'query_vars', array( $self, 'register_query_var' ) );
		add_action( 'template_redirect', array( $self, 'maybe_render_reader' ) );

		add_action( 'woocommerce_single_product_summary', array( $self, 'render_product_button' ), 35 );
		add_shortcode( 'sbr_read_button', array( $self, 'shortcode_read_button' ) );
	}

	/**
	 * Registers the pretty /read-book/ URL and flushes rewrite rules once
	 * per plugin version (avoids a manual "Save Permalinks" step).
	 */
	public function register_rewrite() {
		add_rewrite_rule( '^read-book/?$', 'index.php?sbr_reader=1', 'top' );

		if ( get_option( 'sbr_rewrite_version' ) !== SBR_VERSION ) {
			flush_rewrite_rules();
			update_option( 'sbr_rewrite_version', SBR_VERSION );
		}
	}

	public function register_query_var( $vars ) {
		$vars[] = 'sbr_reader';
		return $vars;
	}

	/**
	 * The reader URL for a book. Works with and without pretty permalinks.
	 */
	public static function get_reader_url( $book_id ) {
		$book_id = absint( $book_id );

		if ( get_option( 'permalink_structure' ) ) {
			return add_query_arg( 'book_id', $book_id, home_url( '/read-book/' ) );
		}

		return add_query_arg(
			array(
				'sbr_reader' => 1,
				'book_id'    => $book_id,
			),
			home_url( '/' )
		);
	}

	/**
	 * Intercepts reader requests, runs access checks, and renders the
	 * full-screen template (no theme header/footer).
	 */
	public function maybe_render_reader() {
		if ( ! get_query_var( 'sbr_reader' ) && empty( $_GET['sbr_reader'] ) ) {
			return;
		}

		$book_id = isset( $_GET['book_id'] ) ? absint( $_GET['book_id'] ) : 0;

		if ( ! is_user_logged_in() ) {
			wp_safe_redirect( wp_login_url( self::get_reader_url( $book_id ) ) );
			exit;
		}

		$file = $book_id ? get_post_meta( $book_id, SBR_Product_Metabox::META_PDF_FILE, true ) : '';
		$path = $file ? trailingslashit( SBR_Activator::get_secure_dir_path() ) . basename( $file ) : '';

		if ( ! $book_id || ! $file || ! file_exists( $path ) ) {
			$this->render_message_page(
				404,
				__( 'Book not found', 'secure-book-reader' ),
				__( 'This book is not available for reading yet.', 'secure-book-reader' ),
				home_url( '/' ),
				__( 'Back to shop', 'secure-book-reader' )
			);
		}

		if ( ! SBR_Access::can_read( get_current_user_id(), $book_id ) ) {
			$this->render_message_page(
				403,
				__( 'No access', 'secure-book-reader' ),
				__( 'Only customers who purchased this book can read it. If you just bought it, please wait until your order is completed.', 'secure-book-reader' ),
				get_permalink( $book_id ),
				__( 'View the book', 'secure-book-reader' )
			);
		}

		$toc       = get_post_meta( $book_id, SBR_Product_Metabox::META_TOC, true );
		$user_id   = get_current_user_id();
		$bookmarks = get_user_meta( $user_id, 'sbr_bookmarks_' . $book_id, true );

		self::$template_data = array(
			'bookId'     => $book_id,
			'bookTitle'  => get_the_title( $book_id ),
			'coverUrl'   => (string) get_the_post_thumbnail_url( $book_id, 'medium' ),
			'streamUrl'  => SBR_Endpoint::get_stream_url( $book_id ),
			'pdfjsUrl'   => SBR_PLUGIN_URL . 'assets/pdfjs/pdf.min.mjs',
			'workerUrl'  => SBR_PLUGIN_URL . 'assets/pdfjs/pdf.worker.min.mjs',
			'backUrl'    => get_permalink( $book_id ),
			'toc'        => is_array( $toc ) ? array_values( $toc ) : array(),
			'ajaxUrl'    => admin_url( 'admin-ajax.php' ),
			'stateNonce' => wp_create_nonce( 'sbr_state_' . $book_id ),
			'watermark'  => wp_get_current_user()->user_email,
			'lastPage'   => (int) get_user_meta( $user_id, 'sbr_last_page_' . $book_id, true ),
			'bookmarks'  => is_array( $bookmarks ) ? array_map( 'intval', $bookmarks ) : array(),
			'i18n'       => array(
				'loadError'      => __( 'Could not load the book. Please refresh the page or try again later.', 'secure-book-reader' ),
				'pageLabel'      => __( 'Page', 'secure-book-reader' ),
				'contents'       => __( 'Contents', 'secure-book-reader' ),
				'pagesCount'     => __( 'pages', 'secure-book-reader' ),
				'searching'      => __( 'Searching…', 'secure-book-reader' ),
				'searchProgress' => __( 'Searching… page %1$s of %2$s', 'secure-book-reader' ),
				'searchResults'  => __( '%s results', 'secure-book-reader' ),
				'searchNone'     => __( 'No results found.', 'secure-book-reader' ),
				'bookmarksEmpty' => __( 'No bookmarks yet. Use the ribbon at the bottom left to bookmark the page you are reading.', 'secure-book-reader' ),
				'removeBookmark' => __( 'Remove bookmark', 'secure-book-reader' ),
			),
		);

		status_header( 200 );
		nocache_headers();
		include SBR_PLUGIN_DIR . 'templates/reader.php';
		exit;
	}

	/**
	 * Minimal standalone page for "not found" / "no access" states.
	 */
	private function render_message_page( $status, $title, $message, $link_url, $link_text ) {
		status_header( $status );
		nocache_headers();
		?>
<!DOCTYPE html>
<html <?php language_attributes(); ?>>
<head>
	<meta charset="<?php echo esc_attr( get_bloginfo( 'charset' ) ); ?>" />
	<meta name="viewport" content="width=device-width, initial-scale=1" />
	<meta name="robots" content="noindex, nofollow" />
	<title><?php echo esc_html( $title ); ?></title>
	<style>
		body { font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif; background: #f0f1f3; display: flex; align-items: center; justify-content: center; min-height: 100vh; margin: 0; }
		.sbr-msg { background: #fff; padding: 40px; border-radius: 8px; box-shadow: 0 2px 10px rgba(0,0,0,.08); max-width: 420px; text-align: center; }
		.sbr-msg a { display: inline-block; margin-top: 16px; color: #fff; background: #2271b1; padding: 10px 22px; border-radius: 4px; text-decoration: none; }
	</style>
</head>
<body>
	<div class="sbr-msg">
		<h1><?php echo esc_html( $title ); ?></h1>
		<p><?php echo esc_html( $message ); ?></p>
		<a href="<?php echo esc_url( $link_url ); ?>"><?php echo esc_html( $link_text ); ?></a>
	</div>
</body>
</html>
		<?php
		exit;
	}

	/**
	 * "Read Now" button on the single product page (only for buyers).
	 */
	public function render_product_button() {
		global $product;

		if ( ! $product instanceof WC_Product ) {
			return;
		}

		echo $this->get_button_html( $product->get_id() ); // phpcs:ignore WordPress.Security.EscapeOutput -- built from escaped parts.
	}

	/**
	 * [sbr_read_button product_id="123"] — for Elementor/custom layouts.
	 */
	public function shortcode_read_button( $atts ) {
		$atts = shortcode_atts( array( 'product_id' => get_the_ID() ), $atts, 'sbr_read_button' );

		return $this->get_button_html( absint( $atts['product_id'] ) );
	}

	/**
	 * Returns the button markup, or an empty string when the current user
	 * may not read the book. Real enforcement stays on the endpoint.
	 */
	private function get_button_html( $product_id ) {
		if ( ! $product_id || ! is_user_logged_in() ) {
			return '';
		}

		if ( ! get_post_meta( $product_id, SBR_Product_Metabox::META_PDF_FILE, true ) ) {
			return '';
		}

		if ( ! SBR_Access::can_read( get_current_user_id(), $product_id ) ) {
			return '';
		}

		return sprintf(
			'<p class="sbr-read-now-wrap"><a class="button sbr-read-now" href="%s">%s</a></p>',
			esc_url( self::get_reader_url( $product_id ) ),
			esc_html__( 'Read Now', 'secure-book-reader' )
		);
	}
}

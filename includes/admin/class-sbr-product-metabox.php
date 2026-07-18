<?php
/**
 * "Book Reader" meta box on the WooCommerce product edit screen:
 * PDF upload into the secure directory, TOC editor (manual + auto-extract),
 * and an admin-only AJAX endpoint that streams the PDF for TOC extraction.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class SBR_Product_Metabox {

	const META_PDF_FILE = '_sbr_pdf_file';
	const META_TOC      = '_sbr_toc';
	const NONCE_ACTION  = 'sbr_save_product_meta';
	const NONCE_FIELD   = 'sbr_product_meta_nonce';
	const AJAX_NONCE    = 'sbr_admin_pdf';

	/**
	 * Registers all hooks for this module.
	 */
	public static function init() {
		$self = new self();

		add_action( 'add_meta_boxes', array( $self, 'register_metabox' ) );
		add_action( 'post_edit_form_tag', array( $self, 'add_form_enctype' ) );
		add_action( 'save_post_product', array( $self, 'save' ), 10, 2 );
		add_action( 'admin_enqueue_scripts', array( $self, 'enqueue_assets' ) );
		add_action( 'wp_ajax_sbr_admin_get_pdf', array( $self, 'ajax_get_pdf' ) );
		add_action( 'admin_notices', array( $self, 'print_notices' ) );
	}

	/**
	 * The product edit form does not accept file uploads by default;
	 * without this attribute the browser never sends the selected PDF.
	 */
	public function add_form_enctype( $post ) {
		if ( $post && 'product' === $post->post_type ) {
			echo ' enctype="multipart/form-data"';
		}
	}

	/**
	 * Adds the meta box to the product edit screen.
	 */
	public function register_metabox() {
		add_meta_box(
			'sbr_book_reader',
			__( 'Book Reader', 'secure-book-reader' ),
			array( $this, 'render_metabox' ),
			'product',
			'normal',
			'default'
		);
	}

	/**
	 * Renders the meta box: current PDF info, upload field, and TOC editor.
	 */
	public function render_metabox( $post ) {
		wp_nonce_field( self::NONCE_ACTION, self::NONCE_FIELD );

		$pdf_file = get_post_meta( $post->ID, self::META_PDF_FILE, true );
		$pdf_path = $pdf_file ? trailingslashit( SBR_Activator::get_secure_dir_path() ) . basename( $pdf_file ) : '';
		$has_pdf  = $pdf_file && file_exists( $pdf_path );
		$toc      = get_post_meta( $post->ID, self::META_TOC, true );

		if ( ! is_array( $toc ) ) {
			$toc = array();
		}
		?>
		<div class="sbr-metabox">

			<h4><?php esc_html_e( 'Book PDF', 'secure-book-reader' ); ?></h4>

			<?php if ( $has_pdf ) : ?>
				<p class="sbr-current-pdf">
					<span class="dashicons dashicons-pdf"></span>
					<strong><?php echo esc_html( $pdf_file ); ?></strong>
					(<?php echo esc_html( size_format( filesize( $pdf_path ) ) ); ?>)
				</p>
				<p>
					<label>
						<input type="checkbox" name="sbr_pdf_remove" value="1" />
						<?php esc_html_e( 'Remove this PDF on save', 'secure-book-reader' ); ?>
					</label>
				</p>
				<p><?php esc_html_e( 'Upload a new file below to replace the current PDF:', 'secure-book-reader' ); ?></p>
			<?php else : ?>
				<p><?php esc_html_e( 'No PDF uploaded yet. Choose the book PDF below and click Update:', 'secure-book-reader' ); ?></p>
			<?php endif; ?>

			<p>
				<input type="file" name="sbr_pdf_upload" accept="application/pdf" />
			</p>

			<hr />

			<h4><?php esc_html_e( 'Table of Contents', 'secure-book-reader' ); ?></h4>
			<p class="description">
				<?php esc_html_e( 'Chapter titles with their page numbers. Use "Extract from PDF" to read the PDF\'s own outline, or add rows manually.', 'secure-book-reader' ); ?>
			</p>

			<table class="widefat sbr-toc-table" id="sbr-toc-table">
				<thead>
					<tr>
						<th><?php esc_html_e( 'Chapter title', 'secure-book-reader' ); ?></th>
						<th class="sbr-col-page"><?php esc_html_e( 'Page', 'secure-book-reader' ); ?></th>
						<th class="sbr-col-actions"></th>
					</tr>
				</thead>
				<tbody id="sbr-toc-rows">
					<?php foreach ( $toc as $row ) : ?>
						<tr>
							<td><input type="text" class="widefat" name="sbr_toc_title[]" value="<?php echo esc_attr( $row['title'] ); ?>" /></td>
							<td><input type="number" min="1" name="sbr_toc_page[]" value="<?php echo esc_attr( $row['page'] ); ?>" /></td>
							<td><button type="button" class="button sbr-remove-row" title="<?php esc_attr_e( 'Remove row', 'secure-book-reader' ); ?>">&times;</button></td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>

			<p class="sbr-toc-actions">
				<button type="button" class="button" id="sbr-add-row">
					<?php esc_html_e( '+ Add chapter', 'secure-book-reader' ); ?>
				</button>
				<button type="button" class="button button-secondary" id="sbr-extract-toc" <?php disabled( ! $has_pdf ); ?>>
					<?php esc_html_e( 'Extract from PDF', 'secure-book-reader' ); ?>
				</button>
				<?php if ( ! $has_pdf ) : ?>
					<span class="description"><?php esc_html_e( '(upload and save a PDF first to enable extraction)', 'secure-book-reader' ); ?></span>
				<?php endif; ?>
			</p>

			<p class="description">
				<?php esc_html_e( 'Remember to click Update to save the PDF and the table of contents.', 'secure-book-reader' ); ?>
			</p>
		</div>
		<?php
	}

	/**
	 * Saves PDF upload/removal and the TOC when the product is saved.
	 */
	public function save( $post_id, $post ) {
		if ( ! isset( $_POST[ self::NONCE_FIELD ] )
			|| ! wp_verify_nonce( sanitize_key( $_POST[ self::NONCE_FIELD ] ), self::NONCE_ACTION ) ) {
			return;
		}

		if ( ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) || wp_is_post_revision( $post_id ) ) {
			return;
		}

		if ( ! current_user_can( 'edit_post', $post_id ) ) {
			return;
		}

		$this->handle_pdf_remove( $post_id );
		$this->handle_pdf_upload( $post_id );
		$this->save_toc( $post_id );
	}

	/**
	 * Deletes the stored PDF if the "remove" checkbox was ticked.
	 */
	private function handle_pdf_remove( $post_id ) {
		if ( empty( $_POST['sbr_pdf_remove'] ) ) {
			return;
		}

		$this->delete_pdf_file( $post_id );
		delete_post_meta( $post_id, self::META_PDF_FILE );
	}

	/**
	 * Validates and moves an uploaded PDF into the secure directory.
	 */
	private function handle_pdf_upload( $post_id ) {
		if ( empty( $_FILES['sbr_pdf_upload'] ) || empty( $_FILES['sbr_pdf_upload']['name'] ) ) {
			return;
		}

		$upload = $_FILES['sbr_pdf_upload'];

		if ( UPLOAD_ERR_OK !== $upload['error'] ) {
			$this->add_notice(
				UPLOAD_ERR_INI_SIZE === $upload['error'] || UPLOAD_ERR_FORM_SIZE === $upload['error']
					? __( 'Book PDF upload failed: the file exceeds the server upload size limit (check upload_max_filesize / post_max_size in php.ini).', 'secure-book-reader' )
					: __( 'Book PDF upload failed: the file did not arrive correctly. Please try again.', 'secure-book-reader' )
			);
			return;
		}

		// Validate the real file content, not just the extension.
		$finfo = new finfo( FILEINFO_MIME_TYPE );
		$mime  = $finfo->file( $upload['tmp_name'] );

		if ( 'application/pdf' !== $mime ) {
			$this->add_notice( __( 'Book PDF upload failed: the file is not a valid PDF.', 'secure-book-reader' ) );
			return;
		}

		// Make sure the secure directory still exists (e.g. after a site migration).
		SBR_Activator::create_secure_storage();

		$filename = 'book-' . $post_id . '-' . strtolower( wp_generate_password( 8, false, false ) ) . '.pdf';
		$dest     = trailingslashit( SBR_Activator::get_secure_dir_path() ) . $filename;

		if ( ! move_uploaded_file( $upload['tmp_name'], $dest ) ) {
			$this->add_notice( __( 'Book PDF upload failed: could not move the file into the secure folder. Check folder permissions.', 'secure-book-reader' ) );
			return;
		}

		// Replace: remove the previous file after the new one is safely stored.
		$this->delete_pdf_file( $post_id );
		update_post_meta( $post_id, self::META_PDF_FILE, $filename );
	}

	/**
	 * Sanitizes and saves the posted TOC rows.
	 */
	private function save_toc( $post_id ) {
		$titles = isset( $_POST['sbr_toc_title'] ) ? array_map( 'sanitize_text_field', (array) wp_unslash( $_POST['sbr_toc_title'] ) ) : array();
		$pages  = isset( $_POST['sbr_toc_page'] ) ? array_map( 'absint', (array) wp_unslash( $_POST['sbr_toc_page'] ) ) : array();

		$toc = array();

		foreach ( $titles as $i => $title ) {
			$page = isset( $pages[ $i ] ) ? $pages[ $i ] : 0;

			if ( '' === $title || $page < 1 ) {
				continue;
			}

			$toc[] = array(
				'title' => $title,
				'page'  => $page,
			);
		}

		if ( $toc ) {
			update_post_meta( $post_id, self::META_TOC, $toc );
		} else {
			delete_post_meta( $post_id, self::META_TOC );
		}
	}

	/**
	 * Deletes the PDF file currently linked to a product, if any.
	 */
	private function delete_pdf_file( $post_id ) {
		$existing = get_post_meta( $post_id, self::META_PDF_FILE, true );

		if ( ! $existing ) {
			return;
		}

		$path = trailingslashit( SBR_Activator::get_secure_dir_path() ) . basename( $existing );

		if ( file_exists( $path ) ) {
			wp_delete_file( $path );
		}
	}

	/**
	 * Streams the product's PDF to logged-in admins/editors for TOC extraction.
	 * The customer-facing endpoint (with purchase checks) comes in Phase 3.
	 */
	public function ajax_get_pdf() {
		check_ajax_referer( self::AJAX_NONCE, 'nonce' );

		$product_id = isset( $_GET['product_id'] ) ? absint( $_GET['product_id'] ) : 0;

		if ( ! $product_id || ! current_user_can( 'edit_post', $product_id ) ) {
			wp_die( esc_html__( 'Forbidden', 'secure-book-reader' ), '', array( 'response' => 403 ) );
		}

		$file = get_post_meta( $product_id, self::META_PDF_FILE, true );
		$path = $file ? trailingslashit( SBR_Activator::get_secure_dir_path() ) . basename( $file ) : '';

		if ( ! $file || ! is_readable( $path ) ) {
			wp_die( esc_html__( 'PDF not found', 'secure-book-reader' ), '', array( 'response' => 404 ) );
		}

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
	 * Loads CSS/JS only on the product edit screen.
	 */
	public function enqueue_assets( $hook ) {
		if ( 'post.php' !== $hook && 'post-new.php' !== $hook ) {
			return;
		}

		$screen = get_current_screen();

		if ( ! $screen || 'product' !== $screen->id ) {
			return;
		}

		wp_enqueue_style(
			'sbr-admin-product',
			SBR_PLUGIN_URL . 'assets/css/sbr-admin-product.css',
			array(),
			SBR_VERSION
		);

		wp_enqueue_script(
			'sbr-admin-product',
			SBR_PLUGIN_URL . 'assets/js/sbr-admin-product.js',
			array( 'jquery' ),
			SBR_VERSION,
			true
		);

		wp_localize_script(
			'sbr-admin-product',
			'SBR_Admin',
			array(
				'ajaxUrl'        => admin_url( 'admin-ajax.php' ),
				'pdfNonce'       => wp_create_nonce( self::AJAX_NONCE ),
				'pdfjsUrl'       => SBR_PLUGIN_URL . 'assets/pdfjs/pdf.min.mjs',
				'pdfjsWorkerUrl' => SBR_PLUGIN_URL . 'assets/pdfjs/pdf.worker.min.mjs',
				'productId'      => isset( $_GET['post'] ) ? absint( $_GET['post'] ) : 0,
				'i18n'           => array(
					'extracting'   => __( 'Extracting…', 'secure-book-reader' ),
					'extractLabel' => __( 'Extract from PDF', 'secure-book-reader' ),
					'noOutline'    => __( 'This PDF has no built-in outline/bookmarks. Please enter the chapters manually.', 'secure-book-reader' ),
					'extractError' => __( 'Could not read the PDF outline. Please enter the chapters manually.', 'secure-book-reader' ),
					'confirmClear' => __( 'This will replace the current TOC rows with the outline found in the PDF. Continue?', 'secure-book-reader' ),
				),
			)
		);
	}

	/**
	 * Queues an admin notice for the current user (survives the save redirect).
	 */
	private function add_notice( $message ) {
		set_transient( 'sbr_admin_notice_' . get_current_user_id(), $message, 60 );
	}

	/**
	 * Prints and clears any queued notice.
	 */
	public function print_notices() {
		$key     = 'sbr_admin_notice_' . get_current_user_id();
		$message = get_transient( $key );

		if ( ! $message ) {
			return;
		}

		delete_transient( $key );
		echo '<div class="notice notice-error is-dismissible"><p>' . esc_html( $message ) . '</p></div>';
	}
}

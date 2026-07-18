<?php
/**
 * Full-screen reader template. Rendered standalone (no theme header/footer)
 * by SBR_Reader::maybe_render_reader(). Layout modeled on the Yuzu reader:
 * floating TOC card, light viewer, slider + page box in the bottom bar.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$sbr_data = SBR_Reader::$template_data;
$sbr_ver  = SBR_VERSION;
?>
<!DOCTYPE html>
<html <?php language_attributes(); ?>>
<head>
	<meta charset="<?php echo esc_attr( get_bloginfo( 'charset' ) ); ?>" />
	<meta name="viewport" content="width=device-width, initial-scale=1" />
	<meta name="robots" content="noindex, nofollow" />
	<title><?php echo esc_html( $sbr_data['bookTitle'] ); ?> — <?php echo esc_html( get_bloginfo( 'name' ) ); ?></title>
	<link rel="stylesheet" href="<?php echo esc_url( SBR_PLUGIN_URL . 'assets/css/sbr-reader.css?ver=' . $sbr_ver ); ?>" />
</head>
<body class="sbr-reader-body">

	<div id="sbr-app">

		<header id="sbr-toolbar">
			<a id="sbr-back" href="<?php echo esc_url( $sbr_data['backUrl'] ); ?>">
				<span class="sbr-chevron">&#8249;</span> <?php esc_html_e( 'Back', 'secure-book-reader' ); ?>
			</a>
			<div id="sbr-toolbar-actions">
				<button id="sbr-search-btn" class="sbr-icon-btn" type="button" title="<?php esc_attr_e( 'Search in book', 'secure-book-reader' ); ?>">
					<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><circle cx="11" cy="11" r="7"/><line x1="16.5" y1="16.5" x2="21" y2="21"/></svg>
				</button>
				<div id="sbr-zoom-group">
					<button id="sbr-zoom-out" class="sbr-icon-btn" type="button" title="<?php esc_attr_e( 'Zoom out', 'secure-book-reader' ); ?>">&minus;</button>
					<span id="sbr-zoom-label">100%</span>
					<button id="sbr-zoom-in" class="sbr-icon-btn" type="button" title="<?php esc_attr_e( 'Zoom in', 'secure-book-reader' ); ?>">+</button>
				</div>
				<button id="sbr-bmlist-btn" class="sbr-icon-btn" type="button" title="<?php esc_attr_e( 'Bookmarks', 'secure-book-reader' ); ?>">
					<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linejoin="round"><path d="M6 3h12v18l-6-4.5L6 21z"/></svg>
				</button>
			</div>
		</header>

		<div id="sbr-search-panel" class="sbr-dropdown" hidden>
			<div class="sbr-dropdown-head">
				<input type="search" id="sbr-search-input" placeholder="<?php esc_attr_e( 'Search in book…', 'secure-book-reader' ); ?>" />
				<button id="sbr-search-close" class="sbr-icon-btn" type="button" title="<?php esc_attr_e( 'Close', 'secure-book-reader' ); ?>">&times;</button>
			</div>
			<div id="sbr-search-status"></div>
			<ul id="sbr-search-results" class="sbr-dropdown-list"></ul>
		</div>

		<div id="sbr-bm-panel" class="sbr-dropdown" hidden>
			<div class="sbr-dropdown-head">
				<span class="sbr-dropdown-title"><?php esc_html_e( 'Bookmarks', 'secure-book-reader' ); ?></span>
				<button id="sbr-bm-close" class="sbr-icon-btn" type="button" title="<?php esc_attr_e( 'Close', 'secure-book-reader' ); ?>">&times;</button>
			</div>
			<ul id="sbr-bm-list" class="sbr-dropdown-list"></ul>
		</div>

		<div id="sbr-body">

			<button id="sbr-toc-open" class="sbr-fab" type="button" title="<?php esc_attr_e( 'Show contents', 'secure-book-reader' ); ?>">&#9776;</button>

			<aside id="sbr-sidebar">
				<div class="sbr-panel-head">
					<?php if ( $sbr_data['coverUrl'] ) : ?>
						<img class="sbr-panel-cover" src="<?php echo esc_url( $sbr_data['coverUrl'] ); ?>" alt="" />
					<?php endif; ?>
					<div class="sbr-panel-meta">
						<div class="sbr-panel-title"><?php echo esc_html( $sbr_data['bookTitle'] ); ?></div>
						<div class="sbr-panel-sub" id="sbr-panel-sub"></div>
					</div>
					<button id="sbr-toc-close" type="button" title="<?php esc_attr_e( 'Hide contents', 'secure-book-reader' ); ?>">&times;</button>
				</div>
				<ul id="sbr-toc"></ul>
			</aside>

			<main id="sbr-viewer">
				<div id="sbr-loading"><?php esc_html_e( 'Loading book…', 'secure-book-reader' ); ?></div>
				<div id="sbr-pages"></div>
			</main>

		</div>

		<footer id="sbr-bottombar">
			<div id="sbr-bottom-left">
				<button id="sbr-bm-toggle" class="sbr-icon-btn" type="button" title="<?php esc_attr_e( 'Bookmark this page', 'secure-book-reader' ); ?>">
					<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linejoin="round"><path d="M6 3h12v18l-6-4.5L6 21z"/></svg>
				</button>
			</div>
			<input type="range" id="sbr-slider" min="1" max="1" value="1" step="1" aria-label="<?php esc_attr_e( 'Reading progress', 'secure-book-reader' ); ?>" />
			<div id="sbr-pager">
				<button id="sbr-prev" type="button" title="<?php esc_attr_e( 'Previous page', 'secure-book-reader' ); ?>">&#8249;</button>
				<input type="number" id="sbr-page-input" min="1" value="1" aria-label="<?php esc_attr_e( 'Go to page', 'secure-book-reader' ); ?>" />
				<span id="sbr-page-total">/ &ndash;</span>
				<button id="sbr-next" type="button" title="<?php esc_attr_e( 'Next page', 'secure-book-reader' ); ?>">&#8250;</button>
			</div>
		</footer>

	</div>

	<script>window.SBR_READER = <?php echo wp_json_encode( $sbr_data ); ?>;</script>
	<script src="<?php echo esc_url( SBR_PLUGIN_URL . 'assets/js/sbr-reader.js?ver=' . $sbr_ver ); ?>"></script>
</body>
</html>

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
			<div id="sbr-toolbar-actions"><!-- search / zoom / bookmark arrive in Phase 6 --></div>
		</header>

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
			<div id="sbr-bottom-left"><!-- bookmark toggle arrives in Phase 6 --></div>
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

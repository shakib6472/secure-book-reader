/**
 * Admin JS for the "Book Reader" product meta box:
 * TOC row add/remove and auto-extraction of the PDF outline via PDF.js.
 */
/* global SBR_Admin, jQuery */
(function ($) {
	'use strict';

	var pdfjsPromise = null;

	/**
	 * Lazily loads the bundled PDF.js library as an ES module.
	 */
	function loadPdfjs() {
		if (!pdfjsPromise) {
			pdfjsPromise = import(SBR_Admin.pdfjsUrl).then(function (lib) {
				lib.GlobalWorkerOptions.workerSrc = SBR_Admin.pdfjsWorkerUrl;
				return lib;
			});
		}
		return pdfjsPromise;
	}

	function addRow(title, page) {
		var $row = $(
			'<tr>' +
				'<td><input type="text" class="widefat" name="sbr_toc_title[]" /></td>' +
				'<td><input type="number" min="1" name="sbr_toc_page[]" /></td>' +
				'<td><button type="button" class="button sbr-remove-row" title="Remove row">&times;</button></td>' +
			'</tr>'
		);

		$row.find('input[name="sbr_toc_title[]"]').val(title || '');
		$row.find('input[name="sbr_toc_page[]"]').val(page || '');
		$('#sbr-toc-rows').append($row);
	}

	/**
	 * Resolves a PDF.js outline destination to a 1-based page number.
	 */
	function resolveDest(doc, dest) {
		var promise = Promise.resolve(dest);

		if (typeof dest === 'string') {
			promise = doc.getDestination(dest);
		}

		return promise
			.then(function (d) {
				if (!Array.isArray(d) || !d[0]) {
					return null;
				}
				return doc.getPageIndex(d[0]).then(function (index) {
					return index + 1;
				});
			})
			.catch(function () {
				return null;
			});
	}

	/**
	 * Recursively flattens the outline tree into {title, page} rows.
	 * Nested chapters get an em-dash prefix per depth level.
	 */
	function walkOutline(doc, items, depth, rows) {
		var chain = Promise.resolve();

		items.forEach(function (item) {
			chain = chain
				.then(function () {
					return resolveDest(doc, item.dest);
				})
				.then(function (page) {
					var prefix = new Array(depth + 1).join('— ');
					rows.push({
						title: prefix + (item.title || '').trim(),
						page: page
					});

					if (item.items && item.items.length) {
						return walkOutline(doc, item.items, depth + 1, rows);
					}
				});
		});

		return chain;
	}

	function extractToc() {
		var $btn = $('#sbr-extract-toc');

		if ($('#sbr-toc-rows tr').length && !window.confirm(SBR_Admin.i18n.confirmClear)) {
			return;
		}

		$btn.prop('disabled', true).text(SBR_Admin.i18n.extracting);

		var url =
			SBR_Admin.ajaxUrl +
			'?action=sbr_admin_get_pdf&product_id=' +
			encodeURIComponent(SBR_Admin.productId) +
			'&nonce=' +
			encodeURIComponent(SBR_Admin.pdfNonce);

		loadPdfjs()
			.then(function (pdfjs) {
				return pdfjs.getDocument({ url: url, isEvalSupported: false }).promise;
			})
			.then(function (doc) {
				return doc.getOutline().then(function (outline) {
					if (!outline || !outline.length) {
						window.alert(SBR_Admin.i18n.noOutline);
						return;
					}

					var rows = [];
					return walkOutline(doc, outline, 0, rows).then(function () {
						$('#sbr-toc-rows').empty();
						rows.forEach(function (row) {
							addRow(row.title, row.page);
						});
					});
				});
			})
			.catch(function (err) {
				window.console && console.error('SBR extract error:', err);
				window.alert(SBR_Admin.i18n.extractError);
			})
			.finally(function () {
				$btn.prop('disabled', false).text(SBR_Admin.i18n.extractLabel);
			});
	}

	$(function () {
		$('#sbr-add-row').on('click', function () {
			addRow('', '');
		});

		$('#sbr-toc-table').on('click', '.sbr-remove-row', function () {
			$(this).closest('tr').remove();
		});

		$('#sbr-extract-toc').on('click', extractToc);
	});
})(jQuery);

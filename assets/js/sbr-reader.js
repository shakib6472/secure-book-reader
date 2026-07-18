/**
 * Secure Book Reader — frontend reader.
 *
 * Loads the PDF through the secure endpoint, renders pages lazily into a
 * scrollable viewer, and provides: TOC card, progress slider, go-to-page,
 * prev/next, keyboard arrows, zoom, in-book search, bookmarks, and
 * last-read-page memory (saved per user via admin-ajax).
 */
/* global SBR_READER */
(function () {
	'use strict';

	var cfg = window.SBR_READER;

	var state = {
		doc: null,
		total: 0,
		current: 1,
		baseScale: 1,
		zoom: 1,
		scale: 1,
		rendered: {},
		observer: null,
		saveTimer: null,
		bookmarks: {},
		textCache: {},
		searchToken: 0
	};

	(cfg.bookmarks || []).forEach(function (p) {
		state.bookmarks[p] = true;
	});

	var el = {
		viewer: document.getElementById('sbr-viewer'),
		pages: document.getElementById('sbr-pages'),
		loading: document.getElementById('sbr-loading'),
		toc: document.getElementById('sbr-toc'),
		panelSub: document.getElementById('sbr-panel-sub'),
		tocOpen: document.getElementById('sbr-toc-open'),
		tocClose: document.getElementById('sbr-toc-close'),
		slider: document.getElementById('sbr-slider'),
		pageInput: document.getElementById('sbr-page-input'),
		pageTotal: document.getElementById('sbr-page-total'),
		prev: document.getElementById('sbr-prev'),
		next: document.getElementById('sbr-next'),
		zoomIn: document.getElementById('sbr-zoom-in'),
		zoomOut: document.getElementById('sbr-zoom-out'),
		zoomLabel: document.getElementById('sbr-zoom-label'),
		searchBtn: document.getElementById('sbr-search-btn'),
		searchPanel: document.getElementById('sbr-search-panel'),
		searchInput: document.getElementById('sbr-search-input'),
		searchClose: document.getElementById('sbr-search-close'),
		searchStatus: document.getElementById('sbr-search-status'),
		searchResults: document.getElementById('sbr-search-results'),
		bmToggle: document.getElementById('sbr-bm-toggle'),
		bmListBtn: document.getElementById('sbr-bmlist-btn'),
		bmPanel: document.getElementById('sbr-bm-panel'),
		bmClose: document.getElementById('sbr-bm-close'),
		bmList: document.getElementById('sbr-bm-list')
	};

	/* ------------------------------------------------------------------ *
	 * Boot
	 * ------------------------------------------------------------------ */

	function boot() {
		import(cfg.pdfjsUrl)
			.then(function (pdfjs) {
				pdfjs.GlobalWorkerOptions.workerSrc = cfg.workerUrl;
				return pdfjs.getDocument({ url: cfg.streamUrl, isEvalSupported: false }).promise;
			})
			.then(function (doc) {
				state.doc = doc;
				state.total = doc.numPages;
				return doc.getPage(1);
			})
			.then(function (page1) {
				computeFitScale(page1);
				buildPlaceholders(page1);
				buildToc();
				setupObserver();
				setupScrollTracking();
				setupNavigation();
				setupZoom();
				setupSearch();
				setupBookmarks();
				setupPersistence();

				el.loading.style.display = 'none';
				el.panelSub.textContent = state.total + ' ' + cfg.i18n.pagesCount;

				if (cfg.lastPage > 1 && cfg.lastPage <= state.total) {
					goToPage(cfg.lastPage, false);
					state.current = cfg.lastPage;
				}

				updateIndicator();
			})
			.catch(function (err) {
				window.console && console.error('SBR reader error:', err);
				el.loading.textContent = cfg.i18n.loadError;
			});
	}

	/* ------------------------------------------------------------------ *
	 * Rendering
	 * ------------------------------------------------------------------ */

	/**
	 * Initial zoom: fit the page width to the viewer (capped for readability).
	 */
	function computeFitScale(page1) {
		var viewport = page1.getViewport({ scale: 1 });
		var available = el.viewer.clientWidth - 48; // viewer padding
		var scale = available / viewport.width;

		state.baseScale = Math.min(Math.max(scale, 0.5), 1.75);
		state.scale = state.baseScale * state.zoom;
	}

	/**
	 * One sized placeholder per page; real rendering happens lazily when a
	 * placeholder approaches the viewport.
	 */
	function buildPlaceholders(page1) {
		var viewport = page1.getViewport({ scale: state.scale });
		var fragment = document.createDocumentFragment();

		for (var n = 1; n <= state.total; n++) {
			var holder = document.createElement('div');
			holder.className = 'sbr-page';
			holder.dataset.page = String(n);
			holder.style.width = Math.floor(viewport.width) + 'px';
			holder.style.height = Math.floor(viewport.height) + 'px';
			fragment.appendChild(holder);
		}

		el.pages.appendChild(fragment);
	}

	function renderPage(n) {
		if (state.rendered[n]) {
			return;
		}
		state.rendered[n] = true;

		var scaleAtRequest = state.scale;

		state.doc.getPage(n).then(function (page) {
			// A zoom happened while this page was loading; the new observer
			// pass will re-render it at the right scale.
			if (scaleAtRequest !== state.scale) {
				state.rendered[n] = false;
				return;
			}

			var viewport = page.getViewport({ scale: state.scale });
			var holder = el.pages.querySelector('.sbr-page[data-page="' + n + '"]');

			if (!holder) {
				return;
			}

			// Pages can differ in size from page 1; correct the placeholder.
			holder.style.width = Math.floor(viewport.width) + 'px';
			holder.style.height = Math.floor(viewport.height) + 'px';

			var dpr = window.devicePixelRatio || 1;
			var canvas = document.createElement('canvas');

			canvas.width = Math.floor(viewport.width * dpr);
			canvas.height = Math.floor(viewport.height * dpr);
			canvas.style.width = Math.floor(viewport.width) + 'px';
			canvas.style.height = Math.floor(viewport.height) + 'px';

			var renderTask = page.render({
				canvasContext: canvas.getContext('2d'),
				viewport: viewport,
				transform: dpr !== 1 ? [dpr, 0, 0, dpr, 0, 0] : null
			});

			return renderTask.promise.then(function () {
				drawWatermark(canvas, viewport, dpr);
				holder.appendChild(canvas);

				// Double rAF so the transition runs after the canvas is painted.
				window.requestAnimationFrame(function () {
					window.requestAnimationFrame(function () {
						canvas.classList.add('sbr-ready');
					});
				});
			});
		});
	}

	/**
	 * Stamps the buyer's email diagonally into the rendered page pixels.
	 * Part of the canvas itself, so it survives screenshots and cannot be
	 * removed from the DOM.
	 */
	function drawWatermark(canvas, viewport, dpr) {
		if (!cfg.watermark) {
			return;
		}

		var ctx = canvas.getContext('2d');
		var fontSize = Math.max(14, Math.floor(viewport.width / 26));

		ctx.save();
		ctx.setTransform(dpr, 0, 0, dpr, 0, 0);
		ctx.globalAlpha = 0.09;
		ctx.fillStyle = '#1a1a2e';
		ctx.font = '600 ' + fontSize + 'px sans-serif';
		ctx.textAlign = 'center';
		ctx.textBaseline = 'middle';
		ctx.translate(viewport.width / 2, viewport.height / 2);
		ctx.rotate(-Math.PI / 6);
		ctx.fillText(cfg.watermark, 0, 0);
		ctx.restore();
	}

	/**
	 * Lazily renders pages as their placeholders come near the viewport.
	 */
	function setupObserver() {
		if (state.observer) {
			state.observer.disconnect();
		}

		state.observer = new IntersectionObserver(
			function (entries) {
				entries.forEach(function (entry) {
					if (entry.isIntersecting) {
						renderPage(parseInt(entry.target.dataset.page, 10));
					}
				});
			},
			{ root: el.viewer, rootMargin: '600px 0px' }
		);

		el.pages.querySelectorAll('.sbr-page').forEach(function (holder) {
			state.observer.observe(holder);
		});
	}

	/* ------------------------------------------------------------------ *
	 * Zoom
	 * ------------------------------------------------------------------ */

	function setupZoom() {
		el.zoomIn.addEventListener('click', function () {
			applyZoom(state.zoom * 1.2);
		});

		el.zoomOut.addEventListener('click', function () {
			applyZoom(state.zoom / 1.2);
		});

		updateZoomLabel();
	}

	function applyZoom(zoom) {
		zoom = Math.min(Math.max(zoom, 0.5), 3);

		if (Math.abs(zoom - state.zoom) < 0.001) {
			return;
		}

		var ratio = (state.baseScale * zoom) / state.scale;

		state.zoom = zoom;
		state.scale = state.baseScale * zoom;
		state.rendered = {};

		// Resize every placeholder proportionally and drop stale canvases;
		// the observer then re-renders whatever is (near) visible.
		el.pages.querySelectorAll('.sbr-page').forEach(function (holder) {
			holder.style.width = parseFloat(holder.style.width) * ratio + 'px';
			holder.style.height = parseFloat(holder.style.height) * ratio + 'px';

			var canvas = holder.querySelector('canvas');
			if (canvas) {
				holder.removeChild(canvas);
			}
		});

		setupObserver();
		goToPage(state.current, false);
		updateZoomLabel();
	}

	function updateZoomLabel() {
		el.zoomLabel.textContent = Math.round(state.zoom * 100) + '%';
	}

	/* ------------------------------------------------------------------ *
	 * TOC
	 * ------------------------------------------------------------------ */

	function buildToc() {
		var items = [];

		if (cfg.toc && cfg.toc.length) {
			items = cfg.toc;
		} else {
			for (var n = 1; n <= state.total; n++) {
				items.push({ title: cfg.i18n.pageLabel + ' ' + n, page: n });
			}
		}

		var fragment = document.createDocumentFragment();

		items.forEach(function (item) {
			var li = document.createElement('li');
			var link = document.createElement('button');

			link.type = 'button';
			link.className = 'sbr-toc-link';
			link.dataset.page = String(item.page);

			var label = document.createElement('span');
			label.className = 'sbr-toc-title';
			label.textContent = item.title;

			var pageNo = document.createElement('span');
			pageNo.className = 'sbr-toc-page';
			pageNo.textContent = item.page;

			link.appendChild(label);
			link.appendChild(pageNo);
			li.appendChild(link);
			fragment.appendChild(li);
		});

		el.toc.innerHTML = '';
		el.toc.appendChild(fragment);

		el.toc.addEventListener('click', function (event) {
			var link = event.target.closest('.sbr-toc-link');
			if (link) {
				goToPage(parseInt(link.dataset.page, 10));

				// On narrow screens the card covers the page; close it after a jump.
				if (window.innerWidth < 768) {
					document.body.classList.add('sbr-toc-closed');
				}
			}
		});
	}

	/* ------------------------------------------------------------------ *
	 * Navigation
	 * ------------------------------------------------------------------ */

	/**
	 * Jumps to a page. Nearby targets glide with a smooth scroll; distant
	 * targets use a quick fade so the reader doesn't blur through 300 pages.
	 * Pass animate=false for instant jumps (e.g. while dragging the slider).
	 */
	function goToPage(n, animate) {
		if (isNaN(n)) {
			return;
		}

		n = Math.min(Math.max(n, 1), state.total);

		var holder = el.pages.querySelector('.sbr-page[data-page="' + n + '"]');

		if (!holder) {
			return;
		}

		var target = holder.offsetTop - 12;

		if (animate === false) {
			el.viewer.scrollTop = target;
			return;
		}

		if (Math.abs(n - state.current) <= 3) {
			el.viewer.scrollTo({ top: target, behavior: 'smooth' });
			return;
		}

		el.pages.classList.add('sbr-jumping');

		window.setTimeout(function () {
			el.viewer.scrollTop = target;

			window.requestAnimationFrame(function () {
				el.pages.classList.remove('sbr-jumping');
			});
		}, 180);
	}

	/**
	 * Tracks which page currently fills the viewport center.
	 */
	function setupScrollTracking() {
		var ticking = false;

		el.viewer.addEventListener('scroll', function () {
			if (ticking) {
				return;
			}
			ticking = true;

			window.requestAnimationFrame(function () {
				ticking = false;

				var middle = el.viewer.scrollTop + el.viewer.clientHeight / 2;
				var holders = el.pages.querySelectorAll('.sbr-page');
				var current = 1;

				for (var i = 0; i < holders.length; i++) {
					if (holders[i].offsetTop <= middle) {
						current = parseInt(holders[i].dataset.page, 10);
					} else {
						break;
					}
				}

				if (current !== state.current) {
					state.current = current;
					updateIndicator();
					schedulePageSave();
				}
			});
		});
	}

	function setupNavigation() {
		el.slider.max = String(state.total);
		el.pageInput.max = String(state.total);
		el.pageTotal.textContent = '/ ' + state.total;

		el.slider.addEventListener('input', function () {
			// Instant while dragging; animation would lag behind the thumb.
			goToPage(parseInt(el.slider.value, 10), false);
		});

		el.pageInput.addEventListener('change', function () {
			goToPage(parseInt(el.pageInput.value, 10));
			el.pageInput.blur();
		});

		el.pageInput.addEventListener('keydown', function (event) {
			if (event.key === 'Enter') {
				goToPage(parseInt(el.pageInput.value, 10));
				el.pageInput.blur();
			}
		});

		el.prev.addEventListener('click', function () {
			goToPage(state.current - 1);
		});

		el.next.addEventListener('click', function () {
			goToPage(state.current + 1);
		});

		document.addEventListener('keydown', function (event) {
			if (event.target === el.pageInput || event.target === el.searchInput) {
				return;
			}

			if (event.key === 'ArrowLeft') {
				goToPage(state.current - 1);
			} else if (event.key === 'ArrowRight') {
				goToPage(state.current + 1);
			}
		});
	}

	function updateIndicator() {
		el.slider.value = String(state.current);

		if (document.activeElement !== el.pageInput) {
			el.pageInput.value = String(state.current);
		}

		var links = el.toc.querySelectorAll('.sbr-toc-link');
		var active = null;

		links.forEach(function (link) {
			var page = parseInt(link.dataset.page, 10);

			link.classList.remove('is-active');

			if (page <= state.current) {
				active = link;
			}
		});

		if (active) {
			active.classList.add('is-active');
		}

		updateBookmarkToggle();
	}

	/* ------------------------------------------------------------------ *
	 * Search
	 * ------------------------------------------------------------------ */

	function setupSearch() {
		el.searchBtn.addEventListener('click', function () {
			togglePanel(el.searchPanel);

			if (!el.searchPanel.hidden) {
				el.searchInput.focus();
			}
		});

		el.searchClose.addEventListener('click', function () {
			el.searchPanel.hidden = true;
			state.searchToken++; // abort any running search
		});

		el.searchInput.addEventListener('keydown', function (event) {
			if (event.key === 'Enter') {
				runSearch(el.searchInput.value.trim());
			}
		});

		el.searchResults.addEventListener('click', function (event) {
			var link = event.target.closest('.sbr-result-link');
			if (link) {
				goToPage(parseInt(link.dataset.page, 10));
			}
		});
	}

	/**
	 * Extracts (and caches) the plain text of one page.
	 */
	function getPageText(n) {
		if (state.textCache[n] !== undefined) {
			return Promise.resolve(state.textCache[n]);
		}

		return state.doc
			.getPage(n)
			.then(function (page) {
				return page.getTextContent();
			})
			.then(function (textContent) {
				var text = textContent.items
					.map(function (item) {
						return item.str;
					})
					.join(' ');

				state.textCache[n] = text;
				return text;
			});
	}

	/**
	 * Walks the book page by page, appending matches as they are found.
	 * A new search (or closing the panel) bumps the token and aborts.
	 */
	function runSearch(query) {
		el.searchResults.innerHTML = '';

		if (query.length < 2) {
			el.searchStatus.textContent = '';
			return;
		}

		var token = ++state.searchToken;
		var q = query.toLowerCase();
		var found = 0;

		el.searchStatus.textContent = cfg.i18n.searching;

		step(1);

		function step(n) {
			if (token !== state.searchToken) {
				return;
			}

			if (n > state.total || found >= 200) {
				el.searchStatus.textContent = found
					? cfg.i18n.searchResults.replace('%s', found)
					: cfg.i18n.searchNone;
				return;
			}

			if (n % 10 === 0) {
				el.searchStatus.textContent = cfg.i18n.searchProgress
					.replace('%1$s', n)
					.replace('%2$s', state.total);
			}

			getPageText(n).then(function (text) {
				if (token !== state.searchToken) {
					return;
				}

				var lower = text.toLowerCase();
				var index = lower.indexOf(q);
				var perPage = 0;

				while (index !== -1 && perPage < 3 && found < 200) {
					appendResult(n, text, index, query.length);
					found++;
					perPage++;
					index = lower.indexOf(q, index + q.length);
				}

				step(n + 1);
			});
		}
	}

	function appendResult(page, text, index, matchLength) {
		var start = Math.max(0, index - 45);
		var end = Math.min(text.length, index + matchLength + 45);

		var li = document.createElement('li');
		var link = document.createElement('button');

		link.type = 'button';
		link.className = 'sbr-result-link';
		link.dataset.page = String(page);

		var pageSpan = document.createElement('span');
		pageSpan.className = 'sbr-result-page';
		pageSpan.textContent = page;

		var snippet = document.createElement('span');
		snippet.className = 'sbr-result-snippet';

		snippet.appendChild(document.createTextNode((start > 0 ? '…' : '') + text.slice(start, index)));

		var mark = document.createElement('mark');
		mark.textContent = text.slice(index, index + matchLength);
		snippet.appendChild(mark);

		snippet.appendChild(document.createTextNode(text.slice(index + matchLength, end) + (end < text.length ? '…' : '')));

		link.appendChild(pageSpan);
		link.appendChild(snippet);
		li.appendChild(link);
		el.searchResults.appendChild(li);
	}

	/* ------------------------------------------------------------------ *
	 * Bookmarks
	 * ------------------------------------------------------------------ */

	function setupBookmarks() {
		el.bmToggle.addEventListener('click', function () {
			if (state.bookmarks[state.current]) {
				delete state.bookmarks[state.current];
			} else {
				state.bookmarks[state.current] = true;
			}

			updateBookmarkToggle();
			renderBookmarkList();
			saveState({ bookmarks: JSON.stringify(bookmarkPages()) });
		});

		el.bmListBtn.addEventListener('click', function () {
			renderBookmarkList();
			togglePanel(el.bmPanel);
		});

		el.bmClose.addEventListener('click', function () {
			el.bmPanel.hidden = true;
		});

		el.bmList.addEventListener('click', function (event) {
			var remove = event.target.closest('.sbr-bm-remove');

			if (remove) {
				delete state.bookmarks[parseInt(remove.dataset.page, 10)];
				updateBookmarkToggle();
				renderBookmarkList();
				saveState({ bookmarks: JSON.stringify(bookmarkPages()) });
				return;
			}

			var row = event.target.closest('.sbr-bm-row');
			if (row) {
				goToPage(parseInt(row.dataset.page, 10));
			}
		});
	}

	function bookmarkPages() {
		return Object.keys(state.bookmarks)
			.map(function (p) {
				return parseInt(p, 10);
			})
			.sort(function (a, b) {
				return a - b;
			});
	}

	function renderBookmarkList() {
		var pages = bookmarkPages();

		el.bmList.innerHTML = '';

		if (!pages.length) {
			var empty = document.createElement('li');
			empty.className = 'sbr-dropdown-empty';
			empty.textContent = cfg.i18n.bookmarksEmpty;
			el.bmList.appendChild(empty);
			return;
		}

		pages.forEach(function (page) {
			var li = document.createElement('li');
			var row = document.createElement('div');

			row.className = 'sbr-bm-row';
			row.dataset.page = String(page);

			var label = document.createElement('span');
			label.className = 'sbr-bm-label';
			label.textContent = cfg.i18n.pageLabel + ' ' + page;

			var remove = document.createElement('button');
			remove.type = 'button';
			remove.className = 'sbr-bm-remove';
			remove.dataset.page = String(page);
			remove.title = cfg.i18n.removeBookmark;
			remove.textContent = '×';

			row.appendChild(label);
			row.appendChild(remove);
			li.appendChild(row);
			el.bmList.appendChild(li);
		});
	}

	function updateBookmarkToggle() {
		el.bmToggle.classList.toggle('is-on', !!state.bookmarks[state.current]);
	}

	/* ------------------------------------------------------------------ *
	 * Persistence (last page + bookmarks)
	 * ------------------------------------------------------------------ */

	function saveState(fields) {
		var body = new FormData();

		body.append('action', 'sbr_save_state');
		body.append('book_id', cfg.bookId);
		body.append('nonce', cfg.stateNonce);

		Object.keys(fields).forEach(function (key) {
			body.append(key, fields[key]);
		});

		if (window.fetch) {
			fetch(cfg.ajaxUrl, { method: 'POST', body: body, credentials: 'same-origin', keepalive: true });
		}
	}

	function schedulePageSave() {
		window.clearTimeout(state.saveTimer);

		state.saveTimer = window.setTimeout(function () {
			saveState({ page: state.current });
		}, 1500);
	}

	function setupPersistence() {
		window.addEventListener('pagehide', function () {
			var body = new FormData();

			body.append('action', 'sbr_save_state');
			body.append('book_id', cfg.bookId);
			body.append('nonce', cfg.stateNonce);
			body.append('page', state.current);

			if (navigator.sendBeacon) {
				navigator.sendBeacon(cfg.ajaxUrl, body);
			}
		});
	}

	/* ------------------------------------------------------------------ *
	 * Panels / misc
	 * ------------------------------------------------------------------ */

	function togglePanel(panel) {
		var wasHidden = panel.hidden;

		el.searchPanel.hidden = true;
		el.bmPanel.hidden = true;

		panel.hidden = !wasHidden;
	}

	el.tocOpen.addEventListener('click', function () {
		document.body.classList.remove('sbr-toc-closed');
	});

	el.tocClose.addEventListener('click', function () {
		document.body.classList.add('sbr-toc-closed');
	});

	// Start with the TOC closed on narrow screens.
	if (window.innerWidth < 768) {
		document.body.classList.add('sbr-toc-closed');
	}

	/* ------------------------------------------------------------------ *
	 * View-only hardening: no right-click, no drag-save, no Ctrl+P/S.
	 * (Deterrents against casual copying, not DRM.)
	 * ------------------------------------------------------------------ */

	document.addEventListener('contextmenu', function (event) {
		event.preventDefault();
	});

	document.addEventListener('dragstart', function (event) {
		event.preventDefault();
	});

	document.addEventListener('keydown', function (event) {
		if (!event.ctrlKey && !event.metaKey) {
			return;
		}

		var key = event.key.toLowerCase();

		if (key === 'p' || key === 's') {
			event.preventDefault();
		}
	});

	boot();
})();

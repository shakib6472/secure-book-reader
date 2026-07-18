/**
 * Secure Book Reader — frontend reader.
 *
 * Loads the PDF through the secure endpoint, renders pages lazily into a
 * scrollable viewer, builds the TOC card (stored TOC or an automatic
 * "Page N" list), and wires all navigation: TOC jumps, progress slider,
 * go-to-page box, prev/next buttons, and keyboard arrows.
 */
/* global SBR_READER */
(function () {
	'use strict';

	var cfg = window.SBR_READER;

	var state = {
		doc: null,
		total: 0,
		current: 1,
		scale: 1,
		rendered: {},
		observer: null
	};

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
		next: document.getElementById('sbr-next')
	};

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
				el.loading.style.display = 'none';
				el.panelSub.textContent = state.total + ' ' + cfg.i18n.pagesCount;
				updateIndicator();
			})
			.catch(function (err) {
				window.console && console.error('SBR reader error:', err);
				el.loading.textContent = cfg.i18n.loadError;
			});
	}

	/**
	 * Initial zoom: fit the page width to the viewer (capped for readability).
	 */
	function computeFitScale(page1) {
		var viewport = page1.getViewport({ scale: 1 });
		var available = el.viewer.clientWidth - 48; // viewer padding
		var scale = available / viewport.width;

		state.scale = Math.min(Math.max(scale, 0.5), 1.75);
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

		state.doc.getPage(n).then(function (page) {
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
				holder.appendChild(canvas);
			});
		});
	}

	/**
	 * Lazily renders pages as their placeholders come near the viewport.
	 */
	function setupObserver() {
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

	/**
	 * TOC card: stored chapter TOC, or an automatic "Page N" list
	 * when the book has no chapter data.
	 */
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

	function goToPage(n) {
		if (isNaN(n)) {
			return;
		}

		n = Math.min(Math.max(n, 1), state.total);

		var holder = el.pages.querySelector('.sbr-page[data-page="' + n + '"]');

		if (holder) {
			el.viewer.scrollTop = holder.offsetTop - 12;
		}
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
				}
			});
		});
	}

	/**
	 * Bottom bar + keyboard navigation.
	 */
	function setupNavigation() {
		el.slider.max = String(state.total);
		el.pageInput.max = String(state.total);
		el.pageTotal.textContent = '/ ' + state.total;

		el.slider.addEventListener('input', function () {
			goToPage(parseInt(el.slider.value, 10));
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
			if (event.target === el.pageInput) {
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

	boot();
})();

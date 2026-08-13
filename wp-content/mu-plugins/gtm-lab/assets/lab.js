/**
 * dataLayer Lab — behaviour layer.
 *
 * Declarative: reads data-dl-* attributes off the markup in sections.php and does
 * the pushing. Loaded deferred in the footer — it deliberately does NOT need to
 * run before gtm.js, because everything here is user-initiated.
 */
(function () {
	'use strict';

	var CFG = window.DLLAB_CFG || {};
	window.dataLayer = window.dataLayer || [];

	/* ------------------------------------------------------------------ */
	/* Core                                                               */
	/* ------------------------------------------------------------------ */

	function push(event, params) {
		var payload = Object.assign({ event: event }, params || {});
		window.dataLayer.push(payload);
		return payload;
	}

	function params(el) {
		var raw = el && el.getAttribute('data-dl-params');
		if (!raw) { return {}; }
		try { return JSON.parse(raw); }
		catch (e) { console.warn('[dllab] bad data-dl-params on', el, e); return { _parse_error: true }; }
	}

	/** Descriptive context about the element, mirroring GTM's built-in click variables. */
	function elementContext(el, clicked) {
		return {
			element_id: el.id || undefined,
			element_classes: el.className || undefined,
			element_text: (el.textContent || '').trim().slice(0, 100) || undefined,
			element_url: el.href || undefined,
			// This is the distinction GTM trips people up with: what you bound to
			// vs. what the pointer was actually over.
			click_target_tag: clicked ? clicked.tagName.toLowerCase() : undefined,
			click_target_is_child: clicked ? clicked !== el : undefined
		};
	}

	var lastFired = {};
	function deduped(key, ms) {
		var now = Date.now();
		if (lastFired[key] && now - lastFired[key] < ms) { return false; }
		lastFired[key] = now;
		return true;
	}

	/* ------------------------------------------------------------------ */
	/* Clicks (delegated — works for elements added after page load)      */
	/* ------------------------------------------------------------------ */

	document.addEventListener('click', function (e) {
		var el = e.target.closest('[data-dl-click]');
		if (!el) { return; }

		var name = el.getAttribute('data-dl-click');
		var dedupeMs = parseInt(el.getAttribute('data-dl-dedupe') || '0', 10);
		if (dedupeMs && !deduped(name + (el.id || ''), dedupeMs)) {
			console.info('[dllab] suppressed duplicate', name);
			return;
		}

		if (el.hasAttribute('data-dl-prevent')) { e.preventDefault(); }

		var extra = params(el);
		if (el.hasAttribute('data-dl-oversize')) {
			extra = {
				a_parameter_name_that_is_well_over_the_forty_character_limit: 'kept here, dropped by GA4',
				long_value: 'x'.repeat(140) + ' <- 140 chars, GA4 truncates at 100'
			};
		}

		var payload = Object.assign(elementContext(el, e.target), extra);

		// eventCallback: navigate only once GTM reports its tags are done.
		var cbUrl = el.getAttribute('data-dl-callback');
		if (cbUrl) {
			e.preventDefault();
			var t0 = performance.now();
			push(name, Object.assign({}, payload, {
				eventTimeout: 2000,
				eventCallback: function (containerId) {
					console.info('[dllab] eventCallback after %sms from %s', Math.round(performance.now() - t0), containerId);
					alert('eventCallback fired after ' + Math.round(performance.now() - t0) + 'ms.\nNavigation would happen now.');
				}
			}));
			return;
		}

		push(name, payload);

		// Navigation race demo.
		var nav = el.getAttribute('data-dl-navigate');
		if (nav) {
			e.preventDefault();
			var delay = parseInt(el.getAttribute('data-dl-delay') || '0', 10);
			console.info('[dllab] navigating in %sms — watch whether the hit leaves first', delay);
			setTimeout(function () {
				alert('Would navigate to ' + nav + ' after ' + delay + 'ms.\nCheck the GA4 hits tab: did it get out?');
			}, delay);
		}
	}, true);

	/* ------------------------------------------------------------------ */
	/* Hover                                                              */
	/* ------------------------------------------------------------------ */

	document.querySelectorAll('[data-dl-hover]').forEach(function (el) {
		var dwellAttr = el.getAttribute('data-dl-hover-dwell');
		var dwell = dwellAttr !== null ? parseInt(dwellAttr, 10) : (CFG.hoverDwell || 300);
		var t = null;

		el.addEventListener('mouseenter', function () {
			t = setTimeout(function () {
				push(el.getAttribute('data-dl-hover'), Object.assign(elementContext(el), params(el), { hover_dwell_ms: dwell }));
				el.classList.add('is-tracked');
			}, dwell);
		});
		el.addEventListener('mouseleave', function () {
			clearTimeout(t);
			el.classList.remove('is-tracked');
		});
	});

	/* ------------------------------------------------------------------ */
	/* Element visibility (impressions)                                   */
	/* ------------------------------------------------------------------ */

	if ('IntersectionObserver' in window) {
		var io = new IntersectionObserver(function (entries) {
			entries.forEach(function (entry) {
				if (!entry.isIntersecting) { return; }
				var el = entry.target;
				var once = el.getAttribute('data-dl-visible-once') !== '0';
				if (once && el.__dllabSeen) { return; }
				el.__dllabSeen = true;

				var name = el.getAttribute('data-dl-visible');
				var extra = params(el);
				// Ecommerce lists get their items collected automatically.
				if (name === 'view_item_list') {
					pushEcommerce('view_item_list', collectItems(el), extra);
				} else {
					push(name, Object.assign(elementContext(el), extra, { percent_visible: 50 }));
				}
				if (once) { io.unobserve(el); }
			});
		}, { threshold: 0.5 });

		document.querySelectorAll('[data-dl-visible]').forEach(function (el) { io.observe(el); });
	}

	/* ------------------------------------------------------------------ */
	/* Text fields and selects — names and shapes only, never values      */
	/* ------------------------------------------------------------------ */

	var formStarted = {};

	document.querySelectorAll('[data-dl-input]').forEach(function (el) {
		var field = el.getAttribute('data-dl-input');
		var form = el.closest('[data-dl-form]');
		var formId = form ? form.getAttribute('data-dl-form') : 'no_form';
		var typed = false;
		var debounce = null;

		function base() {
			return {
				form_id: formId,
				field_name: field,
				field_type: el.type || el.tagName.toLowerCase()
			};
		}

		el.addEventListener('focus', function () {
			if (!formStarted[formId]) {
				formStarted[formId] = true;
				push('form_start', { form_id: formId, first_field: field });
			}
			push('form_field_focus', base());
		});

		el.addEventListener('input', function () {
			typed = true;
			clearTimeout(debounce);
			debounce = setTimeout(function () {
				push('form_field_input', Object.assign(base(), {
					// Length, not content. This is the whole point.
					value_length: String(el.value || '').length,
					value_length_bucket: bucket(String(el.value || '').length),
					is_valid: el.checkValidity ? el.checkValidity() : undefined
				}));
			}, CFG.inputDebounce || 750);
		});

		el.addEventListener('change', function () {
			// For selects / radios / checkboxes the chosen option IS a legitimate
			// dimension — it is a value you authored, not one the user typed.
			var isChoice = /^(select-one|select-multiple|radio|checkbox)$/.test(el.type || '');
			push('form_field_change', Object.assign(base(), isChoice ? {
				field_value: el.type === 'checkbox' ? el.checked : el.value
			} : {}));
		});

		el.addEventListener('blur', function () {
			clearTimeout(debounce);
			push('form_field_blur', Object.assign(base(), {
				was_completed: !!String(el.value || '').length,
				was_typed_in: typed,
				is_valid: el.checkValidity ? el.checkValidity() : undefined
			}));
		});
	});

	function bucket(n) {
		if (n === 0) { return '0'; }
		if (n < 5) { return '1-4'; }
		if (n < 20) { return '5-19'; }
		if (n < 60) { return '20-59'; }
		return '60+';
	}

	/* ------------------------------------------------------------------ */
	/* Scroll milestones                                                  */
	/* ------------------------------------------------------------------ */

	var scrollSeen = {};
	function resetScroll() { scrollSeen = {}; console.info('[dllab] scroll milestones reset'); }

	if ('IntersectionObserver' in window) {
		var sio = new IntersectionObserver(function (entries) {
			entries.forEach(function (entry) {
				if (!entry.isIntersecting) { return; }
				var pct = entry.target.getAttribute('data-dl-scroll');
				if (scrollSeen[pct]) { return; }
				scrollSeen[pct] = true;
				push('scroll_depth', {
					scroll_threshold: Number(pct),
					scroll_direction: 'vertical',
					scroll_unit: 'percent'
				});
				entry.target.classList.add('is-hit');
			});
		}, { threshold: 0.1 });
		document.querySelectorAll('[data-dl-scroll]').forEach(function (el) { sio.observe(el); });
	}

	/** Inner overflow containers — invisible to GTM's built-in Scroll Depth trigger. */
	function trackContainer(el, axis, name) {
		if (!el) { return; }
		var seen = {};
		el.addEventListener('scroll', function () {
			var pct = axis === 'x'
				? el.scrollLeft / (el.scrollWidth - el.clientWidth)
				: el.scrollTop / (el.scrollHeight - el.clientHeight);
			pct = Math.round(pct * 100);
			[25, 50, 75, 100].forEach(function (th) {
				if (pct >= th && !seen[th]) {
					seen[th] = true;
					push('container_scroll', {
						container_id: name,
						scroll_threshold: th,
						scroll_direction: axis === 'x' ? 'horizontal' : 'vertical'
					});
				}
			});
		}, { passive: true });
	}
	trackContainer(document.getElementById('dllab-overflow'), 'y', 'inner_panel');
	trackContainer(document.getElementById('dllab-carousel'), 'x', 'carousel');

	/* ------------------------------------------------------------------ */
	/* Page: clicks                                                       */
	/* ------------------------------------------------------------------ */

	var inject = document.getElementById('dllab-inject');
	if (inject) {
		inject.addEventListener('click', function () {
			var slot = document.getElementById('dllab-injected');
			slot.textContent = 'injecting in 1.5s…';
			setTimeout(function () {
				slot.innerHTML = '';
				var b = document.createElement('button');
				b.className = 'dllab-btn';
				b.setAttribute('data-dl-click', 'late_dom_click');
				b.setAttribute('data-dl-params', '{"injected_at":"post_load"}');
				b.textContent = 'I did not exist at page load';
				slot.appendChild(b);
			}, 1500);
		});
	}

	/* ------------------------------------------------------------------ */
	/* Page: hover — mouseover vs mouseenter counters                     */
	/* ------------------------------------------------------------------ */

	var box = document.getElementById('dllab-mousebox');
	if (box) {
		var cOver = document.getElementById('dllab-c-over');
		var cEnter = document.getElementById('dllab-c-enter');
		var cPushed = document.getElementById('dllab-c-pushed');
		var over = 0, enter = 0, pushed = 0, dwellTimer = null;

		box.addEventListener('mouseover', function () { cOver.textContent = ++over; });
		box.addEventListener('mouseenter', function () {
			cEnter.textContent = ++enter;
			dwellTimer = setTimeout(function () {
				push('box_hover', { box_id: 'mousebox', mouseover_count: over, mouseenter_count: enter });
				cPushed.textContent = ++pushed;
			}, CFG.hoverDwell || 300);
		});
		box.addEventListener('mouseleave', function () { clearTimeout(dwellTimer); });
	}

	/* ------------------------------------------------------------------ */
	/* Page: forms                                                        */
	/* ------------------------------------------------------------------ */

	// Native submit — the event GTM's Form Submission trigger listens for.
	document.querySelectorAll('form[data-dl-form]').forEach(function (form) {
		form.addEventListener('submit', function () {
			push('form_submit', {
				form_id: form.getAttribute('data-dl-form'),
				form_destination: form.action || location.href,
				form_method: (form.method || 'get').toUpperCase()
			});
		});
	});

	// form.submit() bypasses listeners entirely — nothing above will fire.
	document.querySelectorAll('[data-dl-jssubmit]').forEach(function (btn) {
		btn.addEventListener('click', function () {
			var form = btn.closest('form');
			console.warn('[dllab] calling form.submit() — the submit event will NOT fire');
			alert('Calling form.submit() directly.\nNotice that no form_submit push appears in the panel — that is the spec, not a bug.');
			// Not actually submitting, so you can keep reading the panel.
		});
	});

	/**
	 * The static export (GitHub Pages) has no PHP, so the exporter blanks
	 * CFG.ajaxUrl and we simulate the round trip instead. The measurement lesson
	 * is identical either way: the conversion is pushed on the *response*, not on
	 * the submit event.
	 */
	function submitLead() {
		if (!CFG.ajaxUrl) {
			return new Promise(function (resolve) {
				setTimeout(function () {
					resolve({ data: { lead_id: 'lead_' + Math.random().toString(36).slice(2, 10), status: 'received' } });
				}, 600);
			});
		}
		var body = new FormData();
		body.append('action', 'dllab_form');
		body.append('nonce', CFG.nonce);
		return fetch(CFG.ajaxUrl, { method: 'POST', body: body }).then(function (r) { return r.json(); });
	}

	var ajaxForm = document.getElementById('dllab-form-ajax');
	if (ajaxForm) {
		ajaxForm.addEventListener('submit', function (e) {
			e.preventDefault();
			if (!ajaxForm.checkValidity()) { ajaxForm.reportValidity(); return; }

			push('form_submit_attempt', { form_id: 'ajax_form' });

			submitLead()
				.then(function (json) {
					// Push on the RESPONSE, not the submit. This is the conversion.
					push('generate_lead', {
						form_id: 'ajax_form',
						lead_id: json.data && json.data.lead_id,
						currency: 'GBP',
						value: 50.0
					});
					var ok = document.getElementById('dllab-ajax-success');
					ok.hidden = false; // Element Visibility trigger fires here as a fallback
				})
				.catch(function (err) {
					push('form_error', { form_id: 'ajax_form', error_type: 'network' });
					console.error(err);
				});
		});
	}

	var validateForm = document.getElementById('dllab-form-validate');
	if (validateForm) {
		validateForm.addEventListener('submit', function (e) { e.preventDefault(); });
		validateForm.addEventListener('invalid', function (e) {
			push('form_validation_error', {
				form_id: 'validated_form',
				field_name: e.target.name,
				error_type: e.target.validity.valueMissing ? 'required' : 'format'
			});
		}, true); // invalid does not bubble — capture phase is mandatory here
	}

	// Multi-step
	var steps = document.getElementById('dllab-steps');
	if (steps) {
		var show = function (n) {
			steps.querySelectorAll('.dllab-step').forEach(function (s) {
				s.hidden = Number(s.dataset.step) !== n;
			});
			var active = steps.querySelector('.dllab-step[data-step="' + n + '"]');
			push('form_step_view', { form_id: 'multi_step', step_number: n, step_name: active.dataset.stepName });
		};
		steps.addEventListener('click', function (e) {
			var next = e.target.closest('[data-dl-step-next]');
			var prev = e.target.closest('[data-dl-step-prev]');
			var done = e.target.closest('[data-dl-step-complete]');
			if (next) { show(Number(next.getAttribute('data-dl-step-next')) + 1); }
			if (prev) { show(Number(prev.getAttribute('data-dl-step-prev')) - 1); }
			if (done) {
				push('form_complete', { form_id: 'multi_step', steps_completed: 3 });
				alert('form_complete pushed.');
			}
		});
	}

	/* ------------------------------------------------------------------ */
	/* Page: SPA                                                          */
	/* ------------------------------------------------------------------ */

	var tabs = document.getElementById('dllab-tabs');
	if (tabs) {
		var panel = document.getElementById('dllab-tabpanel');
		var slow = document.getElementById('dllab-slow-render');

		tabs.addEventListener('click', function (e) {
			var b = e.target.closest('.dllab-tab');
			if (!b) { return; }
			tabs.querySelectorAll('.dllab-tab').forEach(function (x) { x.classList.toggle('is-active', x === b); });

			var route = '/lab-spa/' + b.dataset.tab + '/';

			// 1. History changes immediately. GTM's History Change trigger fires NOW,
			//    while document.title is still the previous route's.
			history.pushState({ tab: b.dataset.tab }, '', route);

			var render = function () {
				document.title = b.dataset.title;
				panel.textContent = b.textContent + ' content.';
				// 2. Only now is the route real. Trigger GA4 on THIS, not on History Change.
				push('virtual_page_view', {
					page_path: route,
					page_title: b.dataset.title,
					page_location: location.origin + route,
					previous_path: document.referrer || undefined
				});
				resetScroll();
			};

			if (slow && slow.checked) { setTimeout(render, 800); } else { render(); }
		});

		window.addEventListener('popstate', function () {
			push('spa_popstate', { page_path: location.pathname });
		});
		window.addEventListener('hashchange', function () {
			push('spa_hashchange', { hash: location.hash, page_path: location.pathname + location.hash });
		});
	}

	var resetBtn = document.getElementById('dllab-reset-scroll');
	if (resetBtn) { resetBtn.addEventListener('click', resetScroll); }

	/* ------------------------------------------------------------------ */
	/* Page: ecommerce                                                    */
	/* ------------------------------------------------------------------ */

	function itemFrom(el, index) {
		var p = el.closest('.dllab-product') || el;
		return {
			item_id: p.dataset.itemId,
			item_name: p.dataset.itemName,
			item_category: p.dataset.cat,
			price: Number(p.dataset.price), // number, not string — see the section notes
			quantity: 1,
			index: index,
			item_list_id: 'lab_grid',
			item_list_name: 'Lab Grid'
		};
	}

	function collectItems(scope) {
		return Array.prototype.map.call((scope || document).querySelectorAll('.dllab-product'), function (p, i) {
			return itemFrom(p, i);
		});
	}

	/** The correct shape: null the object, then push the event. */
	function pushEcommerce(event, items, extra) {
		window.dataLayer.push({ ecommerce: null });
		var value = items.reduce(function (s, i) { return s + i.price * (i.quantity || 1); }, 0);
		window.dataLayer.push(Object.assign({
			event: event,
			ecommerce: Object.assign({
				currency: 'GBP',
				value: Math.round(value * 100) / 100,
				items: items
			}, extra || {})
		}));
	}

	var cart = [];
	function renderCart() {
		var node = document.getElementById('dllab-cart');
		if (!node) { return; }
		node.innerHTML = cart.length
			? cart.map(function (i) { return '<div>' + i.item_name + ' — £' + i.price.toFixed(2) + '</div>'; }).join('')
			: '<em>Cart is empty.</em>';
	}

	document.addEventListener('click', function (e) {
		var b = e.target.closest('[data-dl-ecom]');
		if (!b) { return; }
		var product = b.closest('.dllab-product');
		var index = Array.prototype.indexOf.call(product.parentNode.children, product);
		var item = itemFrom(product, index);
		var action = b.getAttribute('data-dl-ecom');

		if (action === 'select_item') {
			pushEcommerce('select_item', [item], { item_list_id: 'lab_grid', item_list_name: 'Lab Grid' });
			pushEcommerce('view_item', [item]);
		}
		if (action === 'add_to_cart') {
			cart.push(item);
			renderCart();
			pushEcommerce('add_to_cart', [item]);
		}
	});

	document.addEventListener('click', function (e) {
		var b = e.target.closest('[data-dl-ecom-flow]');
		if (!b) { return; }
		var flow = b.getAttribute('data-dl-ecom-flow');

		if (flow === 'bad_types') {
			window.dataLayer.push({ ecommerce: null });
			window.dataLayer.push({
				event: 'add_to_cart',
				ecommerce: {
					currency: 'GBP',
					value: '24.00',                                  // string — GA4 drops the revenue
					items: [{ item_id: 'SKU-002', item_name: 'Tag Manager Tee', price: '24.00', quantity: '1' }]
				}
			});
			console.warn('[dllab] pushed string-typed value/price/quantity — compare against a correct push in DebugView');
			return;
		}

		if (!cart.length) { alert('Add something to the cart first.'); return; }

		var extra = {};
		if (flow === 'add_shipping_info') { extra.shipping_tier = 'Standard'; }
		if (flow === 'add_payment_info') { extra.payment_type = 'Card'; }
		if (flow === 'purchase') {
			extra.transaction_id = 'T-' + Date.now();  // must be unique — GA4 de-dupes on it
			extra.shipping = 4.99;
			extra.tax = 0;
		}
		pushEcommerce(flow, cart, extra);
		if (flow === 'purchase') {
			cart = [];
			renderCart();
		}
	});

	var mergeDemo = document.getElementById('dllab-merge-demo');
	if (mergeDemo) {
		mergeDemo.addEventListener('click', function () {
			// Deliberately WITHOUT the null reset.
			window.dataLayer.push({
				event: 'view_item_list',
				ecommerce: { currency: 'GBP', value: 39.5, items: collectItems() }
			});
			window.dataLayer.push({
				event: 'add_to_cart',
				ecommerce: { currency: 'GBP', value: 3.0, items: [itemFrom(document.querySelectorAll('.dllab-product')[2], 0)] }
			});
			alert('Two pushes done, no ecommerce:null between them.\n\nOpen the Model tab: ecommerce.items still holds 3 entries, ' +
				'and item 0 is a hybrid of the Mug and the Sticker. That is GTM merging arrays index by index.');
		});
	}

	/* ------------------------------------------------------------------ */
	/* Page: edge cases                                                   */
	/* ------------------------------------------------------------------ */

	var shadowOpen = document.getElementById('dllab-shadow-open');
	if (shadowOpen) {
		var so = shadowOpen.attachShadow({ mode: 'open' });
		so.innerHTML = '<style>:host{color-scheme:dark}button{padding:6px 12px;border-radius:6px;border:1px solid #2b3644;background:#1b232c;color:#e3eaf2;cursor:pointer;font:inherit}</style>' +
			'<button id="inner-open">Button inside an OPEN shadow root</button>';
		so.getElementById('inner-open').addEventListener('click', function () {
			push('shadow_click', {
				shadow_mode: 'open',
				real_target: 'button#inner-open',
				retargeted_to: 'div#' + shadowOpen.id, // what GTM's {{Click Element}} resolves to
				note: 'GTM sees the host div, not this button'
			});
		});
	}

	var shadowClosed = document.getElementById('dllab-shadow-closed');
	if (shadowClosed) {
		var sc = shadowClosed.attachShadow({ mode: 'closed' });
		sc.innerHTML = '<style>:host{color-scheme:dark}button{padding:6px 12px;border-radius:6px;border:1px solid #2b3644;background:#1b232c;color:#e3eaf2;cursor:pointer;font:inherit}</style>' +
			'<button>Button inside a CLOSED shadow root</button>';
		sc.querySelector('button').addEventListener('click', function () {
			push('shadow_click', { shadow_mode: 'closed', note: 'only this hand-written listener can see it' });
		});
	}

	var frame = document.getElementById('dllab-iframe');
	if (frame) {
		frame.addEventListener('load', function () {
			var d = frame.contentDocument;
			if (!d) { return; }
			d.body.innerHTML = '<style>:root{color-scheme:dark}body{font:13px system-ui;padding:14px;margin:0;background:#1b232c;color:#e3eaf2}' +
				'button{padding:6px 12px;border-radius:6px;border:1px solid #2b3644;background:#232d38;color:#e3eaf2;cursor:pointer;font:inherit}</style>' +
				'<p>This is a same-origin iframe. The parent GTM container cannot see this button.</p>' +
				'<button id="ib">Click me</button><p id="out"></p>';
			d.getElementById('ib').addEventListener('click', function () {
				d.getElementById('out').textContent = 'Clicked at ' + new Date().toLocaleTimeString() +
					' — nothing appeared in the parent panel.';
				// The postMessage escape hatch:
				parent.postMessage({ __dllab: true, event: 'iframe_click', frame: 'lab' }, location.origin);
			});
		});
		frame.src = 'about:blank';
		window.addEventListener('message', function (e) {
			if (e.origin !== location.origin || !e.data || !e.data.__dllab) { return; }
			push(e.data.event, { frame_id: e.data.frame, transport: 'postMessage' });
		});
	}

	var throwBtn = document.getElementById('dllab-throw');
	if (throwBtn) {
		throwBtn.addEventListener('click', function () {
			setTimeout(function () { throw new Error('dataLayer Lab: deliberate uncaught error'); }, 0);
		});
	}

	var rejectBtn = document.getElementById('dllab-unhandled');
	if (rejectBtn) {
		rejectBtn.addEventListener('click', function () {
			Promise.reject(new Error('dataLayer Lab: unhandled rejection'));
			console.info('[dllab] rejected — GTM\'s JavaScript Error trigger uses window.onerror and will NOT catch this');
		});
	}

	var cbBtn = document.getElementById('dllab-callback');
	if (cbBtn) {
		cbBtn.addEventListener('click', function () {
			var t0 = performance.now();
			window.dataLayer.push({
				event: 'callback_demo',
				eventTimeout: 2000,
				eventCallback: function (id) {
					push('callback_fired', { container_id: id, elapsed_ms: Math.round(performance.now() - t0) });
				}
			});
			setTimeout(function () {
				if (!window.google_tag_manager) {
					push('callback_fired', { container_id: null, elapsed_ms: null, note: 'no container — eventCallback never runs' });
				}
			}, 2500);
		});
	}

	document.addEventListener('click', function (e) {
		var b = e.target.closest('[data-dl-consent]');
		if (!b) { return; }
		var state = b.getAttribute('data-dl-consent');
		window.dataLayer.push(['consent', 'update', {
			analytics_storage: state,
			ad_storage: state,
			ad_user_data: state,
			ad_personalization: state
		}]);
		push('consent_update_demo', { analytics_storage: state });
		console.info('[dllab] consent set to', state);
	});

	var dump = document.getElementById('dllab-dump-model');
	if (dump) {
		dump.addEventListener('click', function () {
			var m = window.__DLLAB_INSPECTOR__ && window.__DLLAB_INSPECTOR__.model();
			if (!m) {
				console.warn('[dllab] no GTM container — there is no merged model, only the raw push queue:', window.dataLayer);
				alert('No GTM container loaded. Set GTM_CONTAINER_ID in .env.\nThe raw push queue is logged to the console.');
				return;
			}
			console.table(m);
			console.log(m);
			alert('Merged model logged to the console. Also visible in the panel’s Model tab.');
		});
	}

	console.info('[dllab] lab.js ready. Inspect with window.__DLLAB_INSPECTOR__');
}());

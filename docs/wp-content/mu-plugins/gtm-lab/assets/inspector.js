/**
 * dataLayer Lab — on-page inspector.
 *
 * MUST be loaded before gtm.js / gtag.js. It wraps three things while they are
 * still native:
 *
 *   1. window.dataLayer.push      → every message, before GTM swallows it
 *   2. navigator.sendBeacon       → how GA4 sends most hits
 *   3. fetch + XMLHttpRequest     → GA4's fallbacks, and anything a Custom HTML tag does
 *
 * Nothing here talks to the network. It is a mirror, not a proxy.
 */
(function () {
	'use strict';

	if (window.__DLLAB_INSPECTOR__) { return; }

	var MAX = 300;
	var pushes = [];
	var hits = [];
	var paused = false;
	var seq = 0;
	var listeners = [];

	window.dataLayer = window.dataLayer || [];

	/* ------------------------------------------------------------------ */
	/* Recording                                                          */
	/* ------------------------------------------------------------------ */

	function record(list, entry) {
		entry.n = ++seq;
		entry.t = new Date();
		list.push(entry);
		if (list.length > MAX) { list.shift(); }
		if (!paused) { listeners.forEach(function (f) { try { f(); } catch (e) {} }); }
	}

	// Anything already queued before we ran (e.g. the page-context push at wp_head priority 1).
	for (var i = 0; i < window.dataLayer.length; i++) {
		record(pushes, { kind: 'push', args: [window.dataLayer[i]], pre: true });
	}

	function wrapPush() {
		var current = window.dataLayer.push;
		if (current.__dllab) { return; }
		var wrapped = function () {
			record(pushes, { kind: 'push', args: Array.prototype.slice.call(arguments) });
			return current.apply(window.dataLayer, arguments);
		};
		wrapped.__dllab = true;
		window.dataLayer.push = wrapped;
	}
	wrapPush();

	// gtm.js replaces dataLayer.push outright when it boots, which would drop our
	// hook. Poll for ~10s and re-wrap on top of whatever GTM installed.
	var tries = 0;
	var timer = setInterval(function () {
		wrapPush();
		if (++tries > 100) { clearInterval(timer); }
	}, 100);

	/* ------------------------------------------------------------------ */
	/* Network capture                                                    */
	/* ------------------------------------------------------------------ */

	var GA_RE = /google-analytics\.com\/(g\/)?collect|analytics\.google\.com\/g\/collect|\/g\/collect/;

	function captureRequest(url, body, transport) {
		try {
			if (!url || !GA_RE.test(String(url))) { return; }
			parseGa4(String(url), body).forEach(function (hit) {
				hit.transport = transport;
				record(hits, hit);
			});
		} catch (e) { /* never break the page for a debug panel */ }
	}

	/**
	 * GA4 measurement protocol v2 is querystring-encoded. A batched POST puts one
	 * event's params per line in the body, with the shared params in the URL.
	 */
	function parseGa4(url, body) {
		var u;
		try { u = new URL(url, location.href); } catch (e) { return []; }
		var shared = u.searchParams;
		var lines = [];

		if (typeof body === 'string' && body.length) {
			lines = body.split('\n').filter(Boolean);
		} else if (body && typeof body.text === 'function') {
			lines = [];
		}

		if (!lines.length) { lines = ['']; }

		return lines.map(function (line) {
			var p = new URLSearchParams(line);
			var get = function (k) { return p.get(k) !== null ? p.get(k) : shared.get(k); };

			var params = {}, userProps = {};
			var walk = function (sp) {
				sp.forEach(function (v, k) {
					if (k.indexOf('ep.') === 0) { params[k.slice(3)] = v; }
					else if (k.indexOf('epn.') === 0) { params[k.slice(4)] = Number(v); }
					else if (k.indexOf('up.') === 0) { userProps[k.slice(3)] = v; }
					else if (k.indexOf('upn.') === 0) { userProps[k.slice(4)] = Number(v); }
				});
			};
			walk(shared); walk(p);

			return {
				kind: 'hit',
				event: get('en') || '(page_view?)',
				measurementId: get('tid'),
				clientId: get('cid'),
				sessionId: get('sid'),
				debug: get('_dbg') === '1' || get('ep.debug_mode') === 'true',
				engagementMs: get('_et') ? Number(get('_et')) : undefined,
				pageLocation: get('dl'),
				pageTitle: get('dt'),
				params: params,
				userProps: userProps,
				url: u.origin + u.pathname
			};
		});
	}

	var nativeBeacon = navigator.sendBeacon && navigator.sendBeacon.bind(navigator);
	if (nativeBeacon) {
		navigator.sendBeacon = function (url, data) {
			if (typeof data === 'string') { captureRequest(url, data, 'sendBeacon'); }
			else { captureRequest(url, null, 'sendBeacon'); }
			return nativeBeacon(url, data);
		};
	}

	var nativeFetch = window.fetch;
	if (nativeFetch) {
		window.fetch = function (input, init) {
			var url = typeof input === 'string' ? input : (input && input.url);
			var body = init && typeof init.body === 'string' ? init.body : null;
			captureRequest(url, body, 'fetch');
			return nativeFetch.apply(window, arguments);
		};
	}

	var nativeOpen = XMLHttpRequest.prototype.open;
	var nativeSend = XMLHttpRequest.prototype.send;
	XMLHttpRequest.prototype.open = function (method, url) {
		this.__dllabUrl = url;
		return nativeOpen.apply(this, arguments);
	};
	XMLHttpRequest.prototype.send = function (body) {
		captureRequest(this.__dllabUrl, typeof body === 'string' ? body : null, 'xhr');
		return nativeSend.apply(this, arguments);
	};

	/* ------------------------------------------------------------------ */
	/* Merged GTM data model                                              */
	/* ------------------------------------------------------------------ */

	function containerIds() {
		var gtm = window.google_tag_manager || {};
		return Object.keys(gtm).filter(function (k) { return /^GTM-/.test(k) && gtm[k] && gtm[k].dataLayer; });
	}

	/**
	 * There is no public API to dump the whole model, so collect every top-level key
	 * we have ever seen pushed and ask the container for each one.
	 */
	function mergedModel() {
		var ids = containerIds();
		if (!ids.length) { return null; }
		var dl = window.google_tag_manager[ids[0]].dataLayer;
		var keys = {};
		pushes.forEach(function (p) {
			var a = p.args && p.args[0];
			if (a && typeof a === 'object' && !Array.isArray(a)) {
				Object.keys(a).forEach(function (k) { keys[k] = 1; });
			}
		});
		var out = { __container: ids[0] };
		Object.keys(keys).sort().forEach(function (k) {
			try { out[k] = dl.get(k); } catch (e) { out[k] = '(error)'; }
		});
		return out;
	}

	/* ------------------------------------------------------------------ */
	/* Panel                                                              */
	/* ------------------------------------------------------------------ */

	var CSS = [
		'#dllab-insp{position:fixed;right:12px;bottom:12px;width:420px;max-width:calc(100vw - 24px);',
		'z-index:2147483000;font:12px/1.45 ui-monospace,SFMono-Regular,Menlo,Consolas,monospace;',
		'background:#11161d;color:#d7dee8;border:1px solid #2b3644;border-radius:10px;',
		'box-shadow:0 12px 40px rgba(0,0,0,.45);display:flex;flex-direction:column;max-height:70vh}',
		'#dllab-insp.is-min{max-height:none}',
		'#dllab-insp.is-min .dli-body,#dllab-insp.is-min .dli-tabs,#dllab-insp.is-min .dli-tools{display:none}',
		'.dli-head{display:flex;align-items:center;gap:8px;padding:8px 10px;cursor:move;background:#161d26;',
		'border-radius:10px 10px 0 0;border-bottom:1px solid #2b3644;user-select:none}',
		'.dli-head b{font-size:11px;letter-spacing:.06em;text-transform:uppercase;color:#8fb4e3}',
		'.dli-head .dli-sp{margin-left:auto;display:flex;gap:4px}',
		'.dli-head button{background:#222c38;border:1px solid #33404f;color:#cbd5e1;border-radius:5px;',
		'padding:2px 7px;font:inherit;cursor:pointer}',
		'.dli-head button:hover{background:#2c3846}',
		'.dli-tabs{display:flex;border-bottom:1px solid #2b3644;background:#131922}',
		'.dli-tabs button{flex:1;background:none;border:0;border-bottom:2px solid transparent;color:#8b97a6;',
		'padding:7px 4px;font:inherit;cursor:pointer}',
		'.dli-tabs button.on{color:#7fd1ff;border-bottom-color:#7fd1ff;background:#101820}',
		'.dli-tabs .dli-badge{display:inline-block;min-width:16px;margin-left:5px;padding:0 4px;border-radius:8px;',
		'background:#2b3644;color:#b9c6d6;font-size:10px}',
		'.dli-tools{display:flex;gap:6px;padding:6px 8px;border-bottom:1px solid #2b3644;background:#131922}',
		'.dli-tools input{flex:1;background:#0c1117;border:1px solid #2b3644;color:#d7dee8;border-radius:5px;',
		'padding:3px 6px;font:inherit}',
		'.dli-body{overflow:auto;padding:6px;flex:1}',
		'.dli-row{border:1px solid #222c38;border-radius:6px;margin-bottom:5px;background:#0e141b;overflow:hidden}',
		'.dli-row>summary{padding:5px 8px;cursor:pointer;list-style:none;display:flex;align-items:baseline;gap:7px}',
		'.dli-row>summary::-webkit-details-marker{display:none}',
		'.dli-row .ev{color:#9ae6a4;font-weight:600;word-break:break-all}',
		'.dli-row .ev.sys{color:#6b7f95;font-weight:400}',
		'.dli-row .meta{margin-left:auto;color:#5f6f82;font-size:10px;white-space:nowrap}',
		'.dli-row pre{margin:0;padding:6px 8px;border-top:1px solid #222c38;white-space:pre-wrap;',
		'word-break:break-word;color:#c3cedb;background:#0a0f14;max-height:280px;overflow:auto}',
		'.dli-hit>summary .ev{color:#ffcf6b}',
		'.dli-tag{font-size:10px;padding:0 5px;border-radius:3px;background:#22303d;color:#8fb4e3}',
		'.dli-empty{padding:18px;text-align:center;color:#5f6f82}',
		'#dllab-insp-open{position:fixed;right:12px;bottom:12px;z-index:2147483000;display:none;',
		'background:#11161d;color:#7fd1ff;border:1px solid #2b3644;border-radius:8px;padding:6px 12px;',
		'font:12px ui-monospace,monospace;cursor:pointer}'
	].join('');

	function el(tag, cls, html) {
		var n = document.createElement(tag);
		if (cls) { n.className = cls; }
		if (html != null) { n.innerHTML = html; }
		return n;
	}

	function fmtTime(d) {
		return d.toTimeString().slice(0, 8) + '.' + String(d.getMilliseconds()).padStart(3, '0');
	}

	function safeJson(v) {
		var seen = new WeakSet();
		return JSON.stringify(v, function (k, val) {
			if (typeof val === 'function') { return '[Function ' + (val.name || 'anonymous') + ']'; }
			if (val instanceof HTMLElement) { return '[HTMLElement ' + val.tagName.toLowerCase() + (val.id ? '#' + val.id : '') + ']'; }
			if (typeof val === 'object' && val !== null) {
				if (seen.has(val)) { return '[Circular]'; }
				seen.add(val);
			}
			return val;
		}, 2);
	}

	function eventNameOf(entry) {
		var a = entry.args && entry.args[0];
		if (a && typeof a === 'object' && a.event) { return String(a.event); }
		// gtag() pushes an arguments object: ['config', 'G-XXX', {...}]
		if (a && typeof a === 'object' && typeof a.length === 'number' && a[0]) { return 'gtag(' + a[0] + ')'; }
		return '(state push, no event key)';
	}

	function build() {
		var style = el('style'); style.textContent = CSS;
		document.head.appendChild(style);

		var root = el('div'); root.id = 'dllab-insp';
		root.innerHTML =
			'<div class="dli-head"><b>dataLayer Lab</b><span class="dli-tag" id="dli-cid">no container</span>' +
			'<span class="dli-sp">' +
			'<button data-a="pause" title="Pause updates">⏸</button>' +
			'<button data-a="clear" title="Clear">clear</button>' +
			'<button data-a="copy" title="Copy visible tab as JSON">copy</button>' +
			'<button data-a="min" title="Minimise">–</button>' +
			'</span></div>' +
			'<div class="dli-tabs">' +
			'<button data-tab="pushes" class="on">Pushes<span class="dli-badge" id="dli-b-p">0</span></button>' +
			'<button data-tab="hits">GA4 hits<span class="dli-badge" id="dli-b-h">0</span></button>' +
			'<button data-tab="model">Model</button>' +
			'</div>' +
			'<div class="dli-tools"><input type="search" placeholder="filter…" id="dli-filter" /></div>' +
			'<div class="dli-body" id="dli-body"></div>';
		document.body.appendChild(root);

		var opener = el('button', null, 'dataLayer ▲');
		opener.id = 'dllab-insp-open';
		document.body.appendChild(opener);

		var body = root.querySelector('#dli-body');
		var filterEl = root.querySelector('#dli-filter');
		var tab = 'pushes';

		root.querySelector('.dli-tabs').addEventListener('click', function (e) {
			var b = e.target.closest('button[data-tab]');
			if (!b) { return; }
			tab = b.dataset.tab;
			root.querySelectorAll('.dli-tabs button').forEach(function (x) { x.classList.toggle('on', x === b); });
			render();
		});

		root.querySelector('.dli-head').addEventListener('click', function (e) {
			var b = e.target.closest('button[data-a]');
			if (!b) { return; }
			var a = b.dataset.a;
			if (a === 'pause') { paused = !paused; b.textContent = paused ? '▶' : '⏸'; if (!paused) { render(); } }
			if (a === 'clear') { pushes.length = 0; hits.length = 0; render(); }
			if (a === 'copy') {
				var data = tab === 'hits' ? hits : (tab === 'model' ? mergedModel() : pushes.map(function (p) { return p.args; }));
				navigator.clipboard.writeText(safeJson(data)).then(function () { b.textContent = 'copied'; setTimeout(function () { b.textContent = 'copy'; }, 900); });
			}
			if (a === 'min') { root.style.display = 'none'; opener.style.display = 'block'; }
		});
		opener.addEventListener('click', function () { root.style.display = 'flex'; opener.style.display = 'none'; render(); });
		filterEl.addEventListener('input', render);

		// Drag by the header.
		(function () {
			var head = root.querySelector('.dli-head'), sx, sy, ox, oy, dragging = false;
			head.addEventListener('mousedown', function (e) {
				if (e.target.closest('button')) { return; }
				dragging = true; sx = e.clientX; sy = e.clientY;
				var r = root.getBoundingClientRect(); ox = r.left; oy = r.top;
				root.style.right = 'auto'; root.style.bottom = 'auto';
				e.preventDefault();
			});
			document.addEventListener('mousemove', function (e) {
				if (!dragging) { return; }
				root.style.left = (ox + e.clientX - sx) + 'px';
				root.style.top = (oy + e.clientY - sy) + 'px';
			});
			document.addEventListener('mouseup', function () { dragging = false; });
		}());

		function rowFor(entry) {
			var d = el('details', 'dli-row' + (entry.kind === 'hit' ? ' dli-hit' : ''));
			var name, extra = '';
			if (entry.kind === 'hit') {
				name = entry.event;
				extra = (entry.measurementId || '') + ' · ' + entry.transport;
			} else {
				name = eventNameOf(entry);
				extra = entry.pre ? 'pre-container' : '';
			}
			var isSys = /^gtm\.|^\(state/.test(name);
			d.innerHTML = '<summary><span class="ev' + (isSys ? ' sys' : '') + '">' +
				name.replace(/[<>&]/g, '') + '</span><span class="meta">' + extra + ' ' + fmtTime(entry.t) + '</span></summary>';
			var pre = el('pre');
			pre.textContent = entry.kind === 'hit'
				? safeJson({ event: entry.event, measurement_id: entry.measurementId, client_id: entry.clientId,
					session_id: entry.sessionId, engagement_ms: entry.engagementMs, page_location: entry.pageLocation,
					page_title: entry.pageTitle, params: entry.params, user_properties: entry.userProps, endpoint: entry.url })
				: safeJson(entry.args.length === 1 ? entry.args[0] : entry.args);
			d.appendChild(pre);
			return d;
		}

		function render() {
			if (root.style.display === 'none') { return; }
			var ids = containerIds();
			root.querySelector('#dli-cid').textContent = ids.length ? ids.join(', ') : 'no container';
			root.querySelector('#dli-b-p').textContent = pushes.length;
			root.querySelector('#dli-b-h').textContent = hits.length;

			var q = filterEl.value.trim().toLowerCase();
			body.innerHTML = '';

			if (tab === 'model') {
				var m = mergedModel();
				if (!m) {
					body.appendChild(el('div', 'dli-empty', 'No GTM container on the page.<br>The merged model is a GTM construct — with no container, only raw pushes exist.'));
					return;
				}
				var pre = el('pre'); pre.textContent = safeJson(m);
				var wrap = el('div', 'dli-row'); wrap.appendChild(pre); body.appendChild(wrap);
				return;
			}

			var list = (tab === 'hits' ? hits : pushes).slice().reverse();
			if (q) {
				list = list.filter(function (e) { return safeJson(e.kind === 'hit' ? e : e.args).toLowerCase().indexOf(q) > -1; });
			}
			if (!list.length) {
				body.appendChild(el('div', 'dli-empty', tab === 'hits'
					? 'No GA4 hits captured yet.<br>If you expect some: check for an ad blocker, and that a GA4 tag is actually firing.'
					: 'Nothing pushed yet.'));
				return;
			}
			list.forEach(function (e) { body.appendChild(rowFor(e)); });
		}

		listeners.push(render);
		setInterval(function () { if (tab === 'model' && !paused) { render(); } }, 1000);
		render();
	}

	if (document.readyState === 'loading') {
		document.addEventListener('DOMContentLoaded', build);
	} else {
		build();
	}

	/* ------------------------------------------------------------------ */

	window.__DLLAB_INSPECTOR__ = {
		pushes: function () { return pushes; },
		hits: function () { return hits; },
		model: mergedModel,
		containers: containerIds,
		clear: function () { pushes.length = 0; hits.length = 0; }
	};
}());

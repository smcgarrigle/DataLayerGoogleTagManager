<?php
/**
 * Markup for the [dl_lab section="..."] demo sections.
 *
 * Every interactive element is declarative: lab.js reads data-dl-* attributes and
 * does the pushing, so you can add new test cases without touching JavaScript.
 *
 *   data-dl-click="event_name"    push on click (delegated — survives DOM injection)
 *   data-dl-hover="event_name"    push on mouseenter after CFG.hoverDwell ms
 *   data-dl-visible="event_name"  push once when >=50% visible (IntersectionObserver)
 *   data-dl-input="field_name"    push focus / first-input / debounced change / blur
 *   data-dl-scroll="25"           push a custom vertical scroll milestone
 *   data-dl-params='{"k":"v"}'    extra params merged into the push
 *
 * @package DataLayerLab
 */

namespace DLLab\Sections;

defined( 'ABSPATH' ) || exit;

/** @var array<string,array{title:string,blurb:string,slug:string}> */
const PAGES = array(
	'clicks'    => array( 'title' => 'Clicks & Links',        'slug' => 'lab-clicks',    'blurb' => 'Buttons, nested targets, outbound, downloads, tel/mailto, and the navigation race.' ),
	'hover'     => array( 'title' => 'Hover & Visibility',    'slug' => 'lab-hover',     'blurb' => 'mouseover vs mouseenter, dwell thresholds, and IntersectionObserver impressions.' ),
	'scroll'    => array( 'title' => 'Scroll Depth',          'slug' => 'lab-scroll',    'blurb' => 'Page milestones, inner overflow containers, and horizontal carousels.' ),
	'forms'     => array( 'title' => 'Forms & Text Input',    'slug' => 'lab-forms',     'blurb' => 'Field engagement, validation failure, native vs AJAX submit, and PII hazards.' ),
	'spa'       => array( 'title' => 'SPA & History',         'slug' => 'lab-spa',       'blurb' => 'pushState tabs, hashchange, and virtual pageviews.' ),
	'ecommerce' => array( 'title' => 'GA4 Ecommerce',         'slug' => 'lab-ecommerce', 'blurb' => 'The full item funnel, and why you must null the ecommerce object.' ),
	'edge'      => array( 'title' => 'Edge Cases & Gotchas',  'slug' => 'lab-edge',      'blurb' => 'Shadow DOM, iframes, late DOM, JS errors, eventCallback, model merging.' ),
);

function render_index(): string {
	$cards = '';
	foreach ( PAGES as $key => $p ) {
		$url     = esc_url( home_url( '/' . $p['slug'] . '/' ) );
		$title   = esc_html( $p['title'] );
		$blurb   = esc_html( $p['blurb'] );
		$cards  .= <<<HTML
		<a class="dllab-card" href="{$url}" data-dl-click="lab_nav" data-dl-params='{"lab_section":"{$key}"}'>
			<h3>{$title}</h3>
			<p>{$blurb}</p>
		</a>
HTML;
	}

	return <<<HTML
<div class="dllab">
	<header class="dllab-hero">
		<h1>dataLayer Lab</h1>
		<p>Every page below pushes structured events into <code>window.dataLayer</code>. The panel in the
		bottom-right shows each push as it happens, the merged GTM data model, and any GA4
		<code>/g/collect</code> hits that leave the browser.</p>
		<p class="dllab-note">Open GTM Preview and GA4 DebugView side by side with this panel — when the three
		disagree, the disagreement is the lesson.</p>
	</header>
	<div class="dllab-grid">{$cards}</div>
	<div class="dllab-panelblock">
		<h3>Warm-up</h3>
		<div class="dllab-row">
			<button class="dllab-btn" data-dl-click="lab_ping" data-dl-params='{"source":"index"}'>Push a test event</button>
			<code>lab_ping</code>
		</div>
		<div class="dllab-row">
			<button class="dllab-btn dllab-btn-ghost" id="dllab-dump-model">Log merged data model to console</button>
			<code>google_tag_manager[id].dataLayer.get()</code>
		</div>
	</div>
</div>
HTML;
}

function render( string $section ): string {
	$fn = __NAMESPACE__ . '\\section_' . $section;
	if ( ! function_exists( $fn ) ) {
		return '<div class="dllab dllab-error">Unknown lab section: <code>' . esc_html( $section ) . '</code></div>';
	}

	// The theme's post-title block is hidden on lab pages (see lab.css), so the
	// page still needs exactly one h1 — this is it.
	$all   = PAGES;
	$meta  = $all[ $section ] ?? array( 'title' => ucfirst( $section ), 'blurb' => '' );
	$title = esc_html( $meta['title'] );
	$blurb = esc_html( $meta['blurb'] );

	$header = <<<HTML
	<header class="dllab-hero">
		<h1>{$title}</h1>
		<p class="dllab-note">{$blurb}</p>
	</header>
HTML;

	return '<div class="dllab">' . $header . $fn() . nav() . '</div>';
}

function nav(): string {
	$links = '';
	foreach ( PAGES as $p ) {
		$links .= sprintf(
			'<a href="%s">%s</a>',
			esc_url( home_url( '/' . $p['slug'] . '/' ) ),
			esc_html( $p['title'] )
		);
	}
	$home = esc_url( home_url( '/' ) );
	return <<<HTML
<nav class="dllab-nav"><a href="{$home}">← All labs</a>{$links}</nav>
HTML;
}

/* ====================================================================== */

function section_clicks(): string {
	return <<<'HTML'
<div class="dllab-panelblock">
	<h3>1 · Plain buttons</h3>
	<p class="dllab-note">The second button wraps its label in <code>&lt;span&gt;</code>s. GTM's
	<code>{{Click Element}}</code> is the <em>deepest</em> element under the pointer, so a trigger of
	<code>Click Classes contains dllab-btn</code> will <strong>not</strong> fire on it. Use
	<code>Click Element matches CSS selector</code> → <code>.dllab-btn, .dllab-btn *</code>.</p>
	<div class="dllab-row">
		<button class="dllab-btn" data-dl-click="cta_click" data-dl-params='{"cta_id":"flat","cta_text":"Simple button"}'>Simple button</button>
		<code>flat text node</code>
	</div>
	<div class="dllab-row">
		<button class="dllab-btn" data-dl-click="cta_click" data-dl-params='{"cta_id":"nested","cta_text":"Nested button"}'>
			<span class="dllab-ico" aria-hidden="true">◆</span><span class="dllab-lbl">Nested button</span>
		</button>
		<code>click target = span.dllab-ico</code>
	</div>
	<div class="dllab-row">
		<button class="dllab-btn" disabled>Disabled button</button>
		<code>no click event at all — pointer-events on a disabled control</code>
	</div>
	<div class="dllab-row">
		<button class="dllab-btn dllab-btn-ghost" data-dl-click="rapid_click" data-dl-dedupe="1000">Rapid click (1s dedupe)</button>
		<code>mash it — only one push per second</code>
	</div>
</div>

<div class="dllab-panelblock">
	<h3>2 · Link types</h3>
	<p class="dllab-note">GA4 Enhanced Measurement already collects <code>click</code> (outbound) and
	<code>file_download</code>. If you build custom tags for these too, you double-count. Pick one.</p>
	<div class="dllab-row">
		<a class="dllab-link" href="/lab-scroll/" data-dl-click="internal_link">Internal link</a>
		<code>same host → no outbound click</code>
	</div>
	<div class="dllab-row">
		<a class="dllab-link" href="https://example.com/" target="_blank" rel="noopener" data-dl-click="outbound_click">External, new tab</a>
		<code>safe: page never unloads</code>
	</div>
	<div class="dllab-row">
		<a class="dllab-link" href="https://example.org/" data-dl-click="outbound_click">External, same tab</a>
		<code>the race — see §3</code>
	</div>
	<div class="dllab-row">
		<a class="dllab-link" href="mailto:hello@example.com" data-dl-click="mailto_click">mailto:</a>
		<code>href contains an address — do not send it to GA4</code>
	</div>
	<div class="dllab-row">
		<a class="dllab-link" href="tel:+441234567890" data-dl-click="tel_click">tel:</a>
		<code>tel_click</code>
	</div>
	<div class="dllab-row">
		<a class="dllab-link" href="/wp-content/uploads/dllab-sample.pdf" download data-dl-click="file_download" data-dl-params='{"file_extension":"pdf","file_name":"dllab-sample.pdf"}'>Download PDF</a>
		<code>Enhanced Measurement fires file_download too</code>
	</div>
	<div class="dllab-row">
		<a class="dllab-link" href="#" data-dl-click="js_link_click" data-dl-prevent="1">Link with preventDefault</a>
		<code>href="#" — GTM "Just Links" still fires</code>
	</div>
</div>

<div class="dllab-panelblock">
	<h3>3 · The navigation race</h3>
	<p class="dllab-note">Same-tab navigation can kill the request before it leaves. GA4 uses
	<code>navigator.sendBeacon</code>, which survives unload — but a GTM Custom HTML tag doing its own
	<code>fetch</code> will not. The buttons below navigate after a delay so you can watch it.</p>
	<div class="dllab-row">
		<button class="dllab-btn" data-dl-click="exit_click" data-dl-navigate="https://example.net/" data-dl-delay="0">Push + navigate immediately</button>
		<code>worst case</code>
	</div>
	<div class="dllab-row">
		<button class="dllab-btn" data-dl-click="exit_click" data-dl-navigate="https://example.net/" data-dl-delay="300">Push + navigate after 300ms</button>
		<code>the "Wait for Tags" pattern</code>
	</div>
	<div class="dllab-row">
		<button class="dllab-btn dllab-btn-ghost" data-dl-click="exit_click_cb" data-dl-callback="https://example.net/">Push with eventCallback</button>
		<code>navigates only once GTM says tags are done</code>
	</div>
</div>

<div class="dllab-panelblock">
	<h3>4 · Elements that did not exist at page load</h3>
	<p class="dllab-note">GTM's click listener is delegated on <code>document</code>, so late DOM is fine
	for clicks. It is <em>not</em> fine for anything you bind yourself in a Custom HTML tag on Page View.</p>
	<div class="dllab-row">
		<button class="dllab-btn dllab-btn-ghost" id="dllab-inject">Inject a button after 1.5s</button>
		<span id="dllab-injected"></span>
	</div>
</div>
HTML;
}

/* ====================================================================== */

function section_hover(): string {
	return <<<'HTML'
<div class="dllab-panelblock">
	<h3>1 · There is no built-in hover trigger in GTM</h3>
	<p class="dllab-note">GTM ships click, link click, form, scroll, visibility, history, timer, JS error and
	YouTube triggers — but nothing for hover. You must push a custom event yourself, which is exactly what
	these do. Note the dwell threshold: without it, a mouse crossing the page generates dozens of events.</p>
	<div class="dllab-cards">
		<div class="dllab-hovercard" data-dl-hover="card_hover" data-dl-params='{"card_id":"alpha"}'>
			<strong>Alpha</strong><span>300ms dwell</span>
		</div>
		<div class="dllab-hovercard" data-dl-hover="card_hover" data-dl-params='{"card_id":"beta"}'>
			<strong>Beta</strong><span>300ms dwell</span>
		</div>
		<div class="dllab-hovercard" data-dl-hover="card_hover" data-dl-hover-dwell="0" data-dl-params='{"card_id":"gamma","dwell":0}'>
			<strong>Gamma</strong><span>no dwell — fires instantly</span>
		</div>
	</div>
</div>

<div class="dllab-panelblock">
	<h3>2 · mouseover vs mouseenter</h3>
	<p class="dllab-note"><code>mouseover</code> bubbles and re-fires every time the pointer crosses a child
	boundary. <code>mouseenter</code> does not. Sweep across the box below and compare the counters — this is
	the single most common cause of a hover implementation blowing an event quota.</p>
	<div class="dllab-mousebox" id="dllab-mousebox">
		<span class="dllab-chip">child</span><span class="dllab-chip">child</span><span class="dllab-chip">child</span>
		<span class="dllab-chip">child</span><span class="dllab-chip">child</span><span class="dllab-chip">child</span>
	</div>
	<div class="dllab-counters">
		<span>mouseover: <b id="dllab-c-over">0</b></span>
		<span>mouseenter: <b id="dllab-c-enter">0</b></span>
		<span>pushed: <b id="dllab-c-pushed">0</b></span>
	</div>
</div>

<div class="dllab-panelblock">
	<h3>3 · Hover-opened menu</h3>
	<p class="dllab-note">Track the <em>intent</em> (menu opened), not the pointer. Sub-item clicks are
	ordinary clicks.</p>
	<div class="dllab-menu" data-dl-hover="menu_open" data-dl-params='{"menu_id":"products"}'>
		<button class="dllab-btn dllab-btn-ghost">Products ▾</button>
		<div class="dllab-menu-list">
			<a href="#" data-dl-click="menu_item_click" data-dl-params='{"menu_id":"products","item":"widgets"}' data-dl-prevent="1">Widgets</a>
			<a href="#" data-dl-click="menu_item_click" data-dl-params='{"menu_id":"products","item":"gadgets"}' data-dl-prevent="1">Gadgets</a>
			<a href="#" data-dl-click="menu_item_click" data-dl-params='{"menu_id":"products","item":"doohickeys"}' data-dl-prevent="1">Doohickeys</a>
		</div>
	</div>
</div>

<div class="dllab-panelblock">
	<h3>4 · Element Visibility (impressions)</h3>
	<p class="dllab-note">GTM's Element Visibility trigger is the most under-used one in the product. It is
	the correct answer to "track my AJAX thank-you message", "track promo impressions", and "track when the
	sticky CTA appears". Scroll down slowly.</p>
	<div class="dllab-spacer">↓ scroll ↓</div>
	<div class="dllab-promo" data-dl-visible="promo_impression" data-dl-params='{"promo_id":"summer","creative":"banner_a"}'>
		<strong>Promo A</strong> — fires once at 50% visible
	</div>
	<div class="dllab-spacer">↓ keep scrolling ↓</div>
	<div class="dllab-promo" data-dl-visible="promo_impression" data-dl-visible-once="0" data-dl-params='{"promo_id":"autumn","creative":"banner_b"}'>
		<strong>Promo B</strong> — fires <em>every</em> time it re-enters the viewport
	</div>
	<div class="dllab-spacer">end</div>
</div>
HTML;
}

/* ====================================================================== */

function section_scroll(): string {
	$blocks = '';
	foreach ( array( 25, 50, 75, 90 ) as $pct ) {
		$blocks .= <<<HTML
		<div class="dllab-scrollmark" data-dl-scroll="{$pct}">
			<span>{$pct}%</span>
			<p>Custom milestone at {$pct}% of document height. GTM's built-in Scroll Depth trigger measures the
			same thing, fires once per threshold per page load, and does <strong>not</strong> reset on SPA route changes.</p>
		</div>
HTML;
	}

	return <<<HTML
<div class="dllab-panelblock">
	<h3>1 · Vertical page depth</h3>
	<p class="dllab-note">GA4 Enhanced Measurement's <code>scroll</code> event only fires at <strong>90%</strong>,
	once per page. If you want 25/50/75 you need your own tag — and then you should turn the Enhanced
	Measurement scroll toggle off, or you get a stray 90% event with different parameters.</p>
</div>
{$blocks}
<div class="dllab-panelblock">
	<h3>2 · Inner overflow container</h3>
	<p class="dllab-note">GTM's Scroll Depth trigger listens on <code>window</code> only. Scrolling the box
	below produces <em>nothing</em> in the built-in trigger. This is why "our long modal has no scroll data"
	is a recurring ticket. The fix is a custom listener like the one wired up here.</p>
	<div class="dllab-overflow" id="dllab-overflow">
		<p>Line 1 — scroll me.</p><p>Line 2</p><p>Line 3</p><p>Line 4</p><p>Line 5</p>
		<p>Line 6</p><p>Line 7</p><p>Line 8</p><p>Line 9</p><p>Line 10</p>
		<p>Line 11</p><p>Line 12</p><p>Line 13</p><p>Line 14</p><p>Line 15 — bottom.</p>
	</div>
</div>

<div class="dllab-panelblock">
	<h3>3 · Horizontal carousel</h3>
	<p class="dllab-note">The built-in trigger does support horizontal depths, but again only on the document.
	A carousel is an overflow container, so the same limitation applies.</p>
	<div class="dllab-carousel" id="dllab-carousel">
		<div>1</div><div>2</div><div>3</div><div>4</div><div>5</div><div>6</div><div>7</div><div>8</div>
	</div>
</div>

<div class="dllab-panelblock">
	<h3>4 · Read depth vs scroll depth</h3>
	<p class="dllab-note">Percent-of-document is a poor engagement proxy on short pages — a 600px page is
	100% scrolled the instant it loads. Element Visibility on the end-of-article marker is usually the better
	signal.</p>
	<div class="dllab-promo" data-dl-visible="article_end" data-dl-params='{"content_id":"scroll_lab"}'>
		End-of-content marker
	</div>
</div>
HTML;
}

/* ====================================================================== */

function section_forms(): string {
	return <<<'HTML'
<div class="dllab-panelblock dllab-warn">
	<h3>⚠ Read this before you instrument a single field</h3>
	<p>Never push a field's <em>value</em> into the dataLayer for anything a user typed. Email addresses,
	names, phone numbers and free-text messages are PII. Sending them to GA4 breaches the Google Analytics
	terms of service and is a documented cause of property deletion — and once it is in, you cannot delete a
	single parameter, only the whole property's data.</p>
	<p>Push the field's <strong>name, index, validity, length bucket, and whether it was completed</strong>.
	The inputs below deliberately push <code>value_length</code> and never <code>value</code>.</p>
</div>

<div class="dllab-panelblock">
	<h3>1 · Field-level engagement</h3>
	<p class="dllab-note">Each field pushes <code>form_field_focus</code> on first focus,
	<code>form_field_input</code> once after 750ms of typing idle, and <code>form_field_blur</code> with a
	completion flag. GA4's Enhanced Measurement <code>form_start</code> only tells you the form was touched —
	this tells you <em>where people give up</em>.</p>
	<form class="dllab-form" id="dllab-form-fields" data-dl-form="lead_form" onsubmit="return false">
		<label>Full name
			<input type="text" name="full_name" data-dl-input="full_name" autocomplete="off" />
		</label>
		<label>Email <span class="dllab-pii">PII — value never pushed</span>
			<input type="email" name="email" data-dl-input="email" autocomplete="off" />
		</label>
		<label>Company size
			<select name="company_size" data-dl-input="company_size">
				<option value="">Choose…</option>
				<option value="1-10">1–10</option>
				<option value="11-50">11–50</option>
				<option value="51-200">51–200</option>
				<option value="200+">200+</option>
			</select>
		</label>
		<fieldset>
			<legend>Contact preference</legend>
			<label class="dllab-inline"><input type="radio" name="pref" value="email" data-dl-input="pref" /> Email</label>
			<label class="dllab-inline"><input type="radio" name="pref" value="phone" data-dl-input="pref" /> Phone</label>
		</fieldset>
		<label class="dllab-inline">
			<input type="checkbox" name="marketing" data-dl-input="marketing" /> Send me updates
		</label>
		<label>Message
			<textarea name="message" rows="3" data-dl-input="message"></textarea>
		</label>
	</form>
</div>

<div class="dllab-panelblock">
	<h3>2 · Native submit (page unloads)</h3>
	<p class="dllab-note">GTM's Form Submission trigger hooks the <code>submit</code> event. It fires here.
	It does <strong>not</strong> fire if JavaScript calls <code>form.submit()</code> directly — that method
	bypasses event listeners entirely, by spec. It also does not fire if the "submit" control is a
	<code>&lt;button type="button"&gt;</code> with a click handler.</p>
	<form class="dllab-form" method="get" action="/lab-forms/" data-dl-form="native_form">
		<label>Search term <input type="text" name="q" data-dl-input="q" /></label>
		<div class="dllab-row">
			<button type="submit" class="dllab-btn">Real submit (reloads)</button>
			<button type="button" class="dllab-btn dllab-btn-ghost" data-dl-jssubmit="1">JS form.submit() — trigger will miss it</button>
		</div>
	</form>
</div>

<div class="dllab-panelblock">
	<h3>3 · Validation failure</h3>
	<p class="dllab-note">Submit this empty. HTML5 validation blocks the submit event, so nothing fires —
	good. But many form plugins validate in JS <em>after</em> the submit event, so GTM records a submission
	that never happened. That is why the Element Visibility trigger on the success message is the reliable
	pattern, not the submit event.</p>
	<form class="dllab-form" id="dllab-form-validate" data-dl-form="validated_form">
		<label>Required email <input type="email" name="email" required data-dl-input="v_email" /></label>
		<button type="submit" class="dllab-btn">Submit</button>
	</form>
</div>

<div class="dllab-panelblock">
	<h3>4 · AJAX submit — no unload, no thank-you page</h3>
	<p class="dllab-note">The single most common broken form measurement. There is no page view to count and
	the submit event fires before the server has said yes. Push <code>generate_lead</code> on the
	<em>response</em>, and set an Element Visibility trigger on the confirmation node as a belt-and-braces
	fallback.</p>
	<form class="dllab-form" id="dllab-form-ajax" data-dl-form="ajax_form">
		<label>Name <input type="text" name="name" data-dl-input="a_name" /></label>
		<label>Email <input type="email" name="email" required data-dl-input="a_email" /></label>
		<button type="submit" class="dllab-btn">Send (600ms round trip)</button>
	</form>
	<div class="dllab-success" id="dllab-ajax-success" hidden data-dl-visible="form_success_visible" data-dl-params='{"form_id":"ajax_form"}'>
		✓ Thanks — we'll be in touch.
	</div>
</div>

<div class="dllab-panelblock">
	<h3>5 · Multi-step form</h3>
	<p class="dllab-note">Push a step event with both a number and a name. Number sorts correctly in
	funnel exploration; name survives you re-ordering the steps next quarter.</p>
	<div class="dllab-steps" id="dllab-steps">
		<div class="dllab-step" data-step="1" data-step-name="details">
			<h4>Step 1 — Details</h4>
			<label>First name <input type="text" data-dl-input="s1_first" /></label>
			<button class="dllab-btn" data-dl-step-next="1">Next</button>
		</div>
		<div class="dllab-step" data-step="2" data-step-name="requirements" hidden>
			<h4>Step 2 — Requirements</h4>
			<label>Budget
				<select data-dl-input="s2_budget">
					<option value="">Choose…</option><option>&lt; £5k</option><option>£5k–£25k</option><option>£25k+</option>
				</select>
			</label>
			<button class="dllab-btn dllab-btn-ghost" data-dl-step-prev="2">Back</button>
			<button class="dllab-btn" data-dl-step-next="2">Next</button>
		</div>
		<div class="dllab-step" data-step="3" data-step-name="confirm" hidden>
			<h4>Step 3 — Confirm</h4>
			<button class="dllab-btn" data-dl-step-complete="3">Finish</button>
		</div>
	</div>
</div>

<div class="dllab-panelblock">
	<h3>6 · Search box (site search)</h3>
	<p class="dllab-note">GA4 Enhanced Measurement picks up site search automatically from the query
	parameter <code>q, s, search, query, keyword</code> on a page view. WordPress uses <code>?s=</code>,
	which <strong>is</strong> in the default list — so this usually works with zero config. Verify before
	you build a custom tag for it.</p>
	<form class="dllab-form" method="get" action="/">
		<label>WordPress search <input type="search" name="s" data-dl-input="site_search" /></label>
		<button type="submit" class="dllab-btn">Search</button>
	</form>
</div>
HTML;
}

/* ====================================================================== */

function section_spa(): string {
	return <<<'HTML'
<div class="dllab-panelblock">
	<h3>1 · pushState tabs</h3>
	<p class="dllab-note">GTM's History Change trigger fires on <code>pushState</code>,
	<code>replaceState</code>, <code>popstate</code> and <code>hashchange</code>. It gives you
	<code>{{New History Fragment}}</code>, <code>{{Old History Fragment}}</code> and
	<code>{{History Source}}</code>. What it does <em>not</em> do is wait for your new content to render —
	so a GA4 page_view fired on History Change will often send the <em>previous</em> page's title.</p>
	<p class="dllab-note">The fix: don't trigger on History Change. Have the app push its own
	<code>virtual_page_view</code> once the route has settled, and trigger on that.</p>
	<div class="dllab-tabs" id="dllab-tabs">
		<button class="dllab-tab is-active" data-tab="overview" data-title="Overview | SPA Lab">Overview</button>
		<button class="dllab-tab" data-tab="pricing" data-title="Pricing | SPA Lab">Pricing</button>
		<button class="dllab-tab" data-tab="faq" data-title="FAQ | SPA Lab">FAQ</button>
	</div>
	<div class="dllab-tabpanel" id="dllab-tabpanel">Overview content.</div>
	<div class="dllab-row">
		<label class="dllab-inline"><input type="checkbox" id="dllab-slow-render" /> Simulate 800ms render delay</label>
		<code>watch document.title lag behind the history event</code>
	</div>
</div>

<div class="dllab-panelblock">
	<h3>2 · Hash navigation</h3>
	<div class="dllab-row">
		<a class="dllab-link" href="#section-a">#section-a</a>
		<a class="dllab-link" href="#section-b">#section-b</a>
		<a class="dllab-link" href="#section-c">#section-c</a>
		<code>hashchange also fires History Change</code>
	</div>
	<p class="dllab-note">GA4 ignores the hash in <code>page_location</code> by default. If your SPA routes on
	hashes you must send it explicitly, or every route collapses into one row.</p>
</div>

<div class="dllab-panelblock">
	<h3>3 · Scroll and timer state does not reset</h3>
	<p class="dllab-note">After a virtual pageview, GTM's Scroll Depth trigger still thinks you are 75% down
	the <em>first</em> route, and Timer triggers keep counting from the original load. Reset your own state on
	<code>virtual_page_view</code>.</p>
	<div class="dllab-row">
		<button class="dllab-btn dllab-btn-ghost" id="dllab-reset-scroll">Reset scroll milestones</button>
		<code>lab-only helper</code>
	</div>
</div>
HTML;
}

/* ====================================================================== */

function section_ecommerce(): string {
	return <<<'HTML'
<div class="dllab-panelblock dllab-warn">
	<h3>⚠ Always push <code>{ ecommerce: null }</code> first</h3>
	<p>GTM's data model <em>merges</em> pushes rather than replacing them, and it merges arrays index by
	index. Push a 3-item <code>view_item_list</code>, then a 1-item <code>add_to_cart</code>, and the
	<code>items</code> array still contains items 2 and 3 from the earlier push. Google's own GA4 docs open
	every ecommerce snippet with <code>dataLayer.push({ ecommerce: null })</code> for exactly this reason.</p>
	<div class="dllab-row">
		<button class="dllab-btn dllab-btn-ghost" id="dllab-merge-demo">Demonstrate the bug (no null reset)</button>
		<code>then check the merged model in the panel</code>
	</div>
</div>

<div class="dllab-panelblock">
	<h3>1 · Item list — <code>view_item_list</code> / <code>select_item</code></h3>
	<p class="dllab-note">The list fires an impression when it scrolls into view, not on page load.</p>
	<div class="dllab-products" id="dllab-products"
		data-dl-visible="view_item_list" data-dl-params='{"item_list_id":"lab_grid","item_list_name":"Lab Grid"}'>
		<div class="dllab-product" data-item-id="SKU-001" data-item-name="Analytics Mug"     data-price="12.50" data-cat="Drinkware">
			<h4>Analytics Mug</h4><span class="dllab-price">£12.50</span>
			<button class="dllab-btn dllab-btn-sm" data-dl-ecom="select_item">View</button>
			<button class="dllab-btn dllab-btn-sm dllab-btn-ghost" data-dl-ecom="add_to_cart">Add</button>
		</div>
		<div class="dllab-product" data-item-id="SKU-002" data-item-name="Tag Manager Tee"   data-price="24.00" data-cat="Apparel">
			<h4>Tag Manager Tee</h4><span class="dllab-price">£24.00</span>
			<button class="dllab-btn dllab-btn-sm" data-dl-ecom="select_item">View</button>
			<button class="dllab-btn dllab-btn-sm dllab-btn-ghost" data-dl-ecom="add_to_cart">Add</button>
		</div>
		<div class="dllab-product" data-item-id="SKU-003" data-item-name="dataLayer Sticker" data-price="3.00"  data-cat="Stationery">
			<h4>dataLayer Sticker</h4><span class="dllab-price">£3.00</span>
			<button class="dllab-btn dllab-btn-sm" data-dl-ecom="select_item">View</button>
			<button class="dllab-btn dllab-btn-sm dllab-btn-ghost" data-dl-ecom="add_to_cart">Add</button>
		</div>
	</div>
</div>

<div class="dllab-panelblock">
	<h3>2 · Cart & checkout</h3>
	<div class="dllab-cart" id="dllab-cart"><em>Cart is empty.</em></div>
	<div class="dllab-row">
		<button class="dllab-btn" data-dl-ecom-flow="view_cart">view_cart</button>
		<button class="dllab-btn" data-dl-ecom-flow="begin_checkout">begin_checkout</button>
		<button class="dllab-btn" data-dl-ecom-flow="add_shipping_info">add_shipping_info</button>
		<button class="dllab-btn" data-dl-ecom-flow="add_payment_info">add_payment_info</button>
		<button class="dllab-btn" data-dl-ecom-flow="purchase">purchase</button>
	</div>
	<p class="dllab-note"><code>purchase</code> needs a unique <code>transaction_id</code>. GA4 de-duplicates
	on it within a rolling window, so a customer refreshing the thank-you page will not double-count — but a
	<em>test</em> transaction ID you reuse will silently vanish. This lab generates a fresh one each time.</p>
</div>

<div class="dllab-panelblock">
	<h3>3 · Parameter gotchas</h3>
	<ul class="dllab-list">
		<li><code>value</code> must be a <strong>number</strong>, not a string. <code>"24.00"</code> is
		accepted by the dataLayer and quietly dropped by GA4's revenue reporting.</li>
		<li><code>currency</code> is required whenever <code>value</code> is set, or the revenue is discarded.</li>
		<li><code>items</code> is capped at 200 entries per event. Longer lists are truncated with no error
		anywhere — not in DebugView, not in the network tab.</li>
		<li>Max 25 parameters per event. Ecommerce item fields do not count against it, but everything you
		bolt on alongside does.</li>
		<li>Item-scoped custom parameters need registering under Admin → Custom definitions with
		<em>item</em> scope, which is a separate list from event-scoped ones.</li>
	</ul>
	<div class="dllab-row">
		<button class="dllab-btn dllab-btn-ghost" data-dl-ecom-flow="bad_types">Push with string value (broken on purpose)</button>
		<code>compare in DebugView</code>
	</div>
</div>
HTML;
}

/* ====================================================================== */

function section_edge(): string {
	return <<<'HTML'
<div class="dllab-panelblock">
	<h3>1 · The dataLayer is a queue, not a state object</h3>
	<p class="dllab-note">Once gtm.js loads it replaces <code>dataLayer.push</code> with its own function.
	Reading <code>window.dataLayer</code> in the console shows you the raw message history, <em>not</em> what
	your variables will resolve to. For the merged model use:</p>
	<pre class="dllab-code">google_tag_manager['GTM-XXXXXXX'].dataLayer.get('page.type')</pre>
	<div class="dllab-row">
		<button class="dllab-btn" data-dl-click="state_a" data-dl-params='{"cart":{"items":3,"coupon":"SAVE10"}}'>Push A (3 items, coupon)</button>
		<button class="dllab-btn" data-dl-click="state_b" data-dl-params='{"cart":{"items":1}}'>Push B (1 item, no coupon)</button>
		<code>coupon persists after B — that is the merge</code>
	</div>
</div>

<div class="dllab-panelblock">
	<h3>2 · Shadow DOM</h3>
	<p class="dllab-note">Events from an <strong>open</strong> shadow root are retargeted at the host element
	when they cross the boundary, so GTM's <code>{{Click Element}}</code> is the custom element, not the
	button you clicked — all your CSS selectors miss. A <strong>closed</strong> root hides it entirely.</p>
	<div class="dllab-row">
		<div id="dllab-shadow-open"></div>
		<code>open root — click element retargets to the host</code>
	</div>
	<div class="dllab-row">
		<div id="dllab-shadow-closed"></div>
		<code>closed root — nothing usable escapes</code>
	</div>
</div>

<div class="dllab-panelblock">
	<h3>3 · Same-origin iframe</h3>
	<p class="dllab-note">A GTM container in the parent page sees <strong>nothing</strong> inside an iframe,
	same-origin or not. Either put the container in the frame too, or postMessage out. This is why embedded
	booking widgets, chat tools and payment iframes look like dead ends in your funnel.</p>
	<iframe class="dllab-iframe" id="dllab-iframe" title="Same-origin iframe" src="about:blank"></iframe>
</div>

<div class="dllab-panelblock">
	<h3>4 · JavaScript errors & timers</h3>
	<div class="dllab-row">
		<button class="dllab-btn dllab-btn-ghost" id="dllab-throw">Throw an uncaught error</button>
		<code>GTM JavaScript Error trigger → gtm.pageError</code>
	</div>
	<div class="dllab-row">
		<button class="dllab-btn dllab-btn-ghost" id="dllab-unhandled">Reject a promise (unhandled)</button>
		<code>NOT caught by the JS Error trigger — window.onerror only</code>
	</div>
</div>

<div class="dllab-panelblock">
	<h3>5 · eventCallback and eventTimeout</h3>
	<p class="dllab-note">A function in the push payload cannot survive JSON serialisation, so it can only be
	set from JavaScript — never from a server-rendered snippet. <code>eventTimeout</code> guarantees the
	callback runs even if a tag hangs.</p>
	<div class="dllab-row">
		<button class="dllab-btn" id="dllab-callback">Push with eventCallback + 2s eventTimeout</button>
		<code>result appears in the panel log</code>
	</div>
</div>

<div class="dllab-panelblock">
	<h3>6 · Consent Mode</h3>
	<p class="dllab-note">If you add a consent plugin later and tags silently stop firing, this is why: GA4
	tags respect <code>analytics_storage</code>. Denied means cookieless pings with no client ID, which look
	like "traffic dropped 90% overnight". Toggle it here and watch the hits change shape.</p>
	<div class="dllab-row">
		<button class="dllab-btn dllab-btn-ghost" data-dl-consent="denied">Set analytics_storage = denied</button>
		<button class="dllab-btn dllab-btn-ghost" data-dl-consent="granted">Set analytics_storage = granted</button>
		<code>gtag('consent','update',…)</code>
	</div>
</div>

<div class="dllab-panelblock">
	<h3>7 · Naming rules you will trip over</h3>
	<ul class="dllab-list">
		<li>Event names are case-sensitive: <code>Purchase</code> and <code>purchase</code> are different events.</li>
		<li>Max 40 chars for event names, 40 for parameter names, 100 for parameter values (silently truncated).</li>
		<li>Reserved prefixes: <code>google_</code>, <code>ga_</code>, <code>firebase_</code>. Reserved
		<code>gtm.</code> prefix inside the dataLayer.</li>
		<li>A custom parameter does not appear in standard reports until you register it as a custom
		dimension — and then only for data collected <em>after</em> registration. It shows in DebugView and
		BigQuery immediately, which is why "it works in DebugView but not in reports" is so common.</li>
		<li>Max 50 event-scoped custom dimensions per property. Spend them deliberately.</li>
	</ul>
	<div class="dllab-row">
		<button class="dllab-btn dllab-btn-ghost" data-dl-click="over_limit_test" data-dl-oversize="1">Push oversized names &amp; values</button>
		<code>accepted here, truncated in GA4</code>
	</div>
</div>
HTML;
}

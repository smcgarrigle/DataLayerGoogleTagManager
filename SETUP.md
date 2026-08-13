# WordPress dataLayer Lab — setup, caveats, pitfalls and hacks

A local WordPress instance wired for Google Tag Manager and GA4, with seven pages of
deliberately instrumented UI: clicks, hover, scroll, text input, SPA routing, ecommerce, and a
page of things that are supposed to break.

Everything is already built and running. This document explains how it fits together, answers the
localhost/GA4 question properly, and collects the things that will otherwise cost you an afternoon
each.

---

## 1. Quick start

```bash
cp .env.example .env && docker compose --profile tools up -d && ./scripts/bootstrap.sh
```

| | |
|---|---|
| Site | <http://localhost:8888/> |
| Admin | <http://localhost:8888/wp-admin/> — `admin` / `admin` |
| Adminer | <http://localhost:8081/> — server `db`, user/pass `wordpress` |
| Lab settings | Settings → dataLayer Lab |

Ports 80, 8000, 8080 and 3306 were already in use on this machine, so the stack uses **8888**
(WordPress), **8081** (Adminer) and **3307** (MariaDB, bound to 127.0.0.1 only).

Then put your GTM container ID in `.env` and restart:

```bash
sed -i 's/^GTM_CONTAINER_ID=.*/GTM_CONTAINER_ID=GTM-XXXXXXX/' .env && docker compose up -d wordpress
```

`make help` lists the rest (`up`, `down`, `bootstrap`, `logs`, `debuglog`, `reset`, `urls`,
`wp CMD="…"`).

### The pages

| URL | What it exercises |
|---|---|
| `/` | Index + warm-up pushes |
| `/lab-clicks/` | Buttons, nested click targets, outbound/mailto/tel/download links, the navigation race, late-injected DOM |
| `/lab-hover/` | Dwell-thresholded hover, `mouseover` vs `mouseenter` counters, hover menus, Element Visibility impressions |
| `/lab-scroll/` | 25/50/75/90% milestones, inner overflow container, horizontal carousel, read-depth marker |
| `/lab-forms/` | Per-field focus/input/blur, validation failure, native vs `form.submit()` vs AJAX, multi-step, site search |
| `/lab-spa/` | `pushState` tabs, hashchange, virtual pageviews, the render-lag trap |
| `/lab-ecommerce/` | Full GA4 item funnel plus a live demo of the `ecommerce: null` merge bug |
| `/lab-edge/` | Shadow DOM, iframes, JS errors, `eventCallback`, Consent Mode, naming limits |

### The inspector panel

Bottom-right of every front-end page, draggable, three tabs:

- **Pushes** — every `dataLayer.push` in order, including the ones that happened before the
  container loaded.
- **GA4 hits** — decoded `/g/collect` requests. It wraps `sendBeacon`, `fetch` and `XHR` before
  gtag/GTM load, so you see the actual outgoing measurement payload with `ep.`/`epn.` parameters
  already unpacked. No extension required.
- **Model** — the *merged* GTM data model via `google_tag_manager['GTM-…'].dataLayer.get()`, which
  is what your variables actually resolve to. This is not the same thing as the Pushes tab, and
  the difference is the source of a lot of confusion (§6.1).

Turn it off in Settings → dataLayer Lab. `window.__DLLAB_INSPECTOR__` exposes the same data to the
console.

---

## 2. Will GA4 work on localhost?

**Yes — with no special configuration.** GA4 does not validate the sending hostname. Hits from
`http://localhost:8888` are accepted, attributed, and reported exactly like any other, with the
hostname recorded as `localhost`. There is no allowlist to add your domain to, and no equivalent of
the old "invalid hostname" filter.

What is true, and what actually bites:

### 2.1 It will pollute your production property

This is the real problem, not connectivity. Every click you make while learning lands in your
reports as a real session, with page paths like `/lab-clicks/` that will sit in your Pages report
forever. GA4 has no data deletion at parameter or hostname granularity — you can delete a whole
property, and that is roughly it.

**Do this before you send a single hit:**

1. **Create a throwaway GA4 property + web data stream just for the lab.** Not a new stream on
   your existing property — a new *property*. Free, unlimited, takes 90 seconds. Everything below
   is a fallback for when you can't.
2. If you must use the real property, set `traffic_type: 'internal'` as a field on the GA4
   configuration tag, then go to **Admin → Data settings → Data filters** and switch the built-in
   *Internal Traffic* filter from **Testing** to **Active**. The filter matches on the
   `traffic_type` parameter, so setting it directly in the tag works even though the IP-based
   "define internal traffic" rule can't distinguish your localhost from your normal browsing.
3. Also switch the **Developer Traffic** data filter to Active. It excludes any event carrying
   `debug_mode`, which is every event you send while GTM Preview is on.

> Filters are not retroactive and take up to ~12 hours to apply after you activate them. Set them
> up *first*.

### 2.2 Debug mode and DebugView

`debug_mode` is what puts events in **Admin → DebugView**. It gets set three ways:

- **GTM Preview mode is on** — GTM adds it to GA4 tags automatically. This is the normal route.
- The **Google Analytics Debugger** Chrome extension.
- Explicitly, as a `debug_mode` field on the GA4 tag — which is what this lab does when you use
  the raw `gtag.js` path (`GA4_MEASUREMENT_ID` with no GTM container).

Two things people get wrong:

- **Debug events still count in normal reports.** DebugView is not a sandbox. Only the *Developer
  Traffic* data filter removes them.
- **DebugView shows one device at a time.** There is a device selector at the top left. If your
  events aren't appearing, you're probably looking at a different device stream, or the events are
  arriving without `debug_mode` at all.

### 2.3 Latency, so you don't debug the wrong thing

| Surface | Lag |
|---|---|
| Inspector panel / DevTools network | instant |
| GTM Preview (Tag Assistant) | instant |
| GA4 DebugView | 5–30 seconds |
| GA4 Realtime | ~30 seconds, 30-minute window |
| Standard reports | **24–48 hours** |
| BigQuery daily export | next day; streaming export is ~minutes |

Nearly every "GA4 isn't working" ticket is someone checking standard reports 10 minutes in. Use
DebugView for correctness, Realtime for smoke tests, and nothing else until tomorrow.

### 2.4 Ad blockers and browser privacy

The single most common cause of "no data on localhost". uBlock Origin, Brave Shields, Firefox
Enhanced Tracking Protection (Strict), Safari, Pi-hole and most corporate DNS all block
`google-analytics.com` and `googletagmanager.com`.

The inspector panel makes this trivially diagnosable: if a push appears in **Pushes** but nothing
appears in **GA4 hits**, either no tag fired (GTM Preview will say) or the request was blocked.

**Use a dedicated Chrome profile for measurement work** with no extensions except Tag Assistant
Companion and the GA Debugger. It is worth the 30 seconds.

### 2.5 Cookies on localhost

`localhost` is treated as a secure context, so the `_ga` and `_ga_<STREAM>` cookies set and persist
normally over plain HTTP. Two traps:

- **Don't use a bare hostname** like `http://wpgtm/`. Hostnames with no dot have historically
  inconsistent cookie behaviour. `localhost` is explicitly special-cased and safe.
- **Don't use `.local`.** It collides with mDNS/Bonjour and produces multi-second DNS stalls.
  `.localhost` and `.test` are reserved by RFC and safe. Chrome resolves `*.localhost` to
  127.0.0.1 with no hosts-file entry at all.

### 2.6 What genuinely does *not* work locally

| Thing | Why | Workaround |
|---|---|---|
| Cross-domain measurement | needs two registrable domains | add `a.test` / `b.test` to your **Windows** hosts file (that's where the browser is) and serve both from the same container |
| Subdomain / cookie-domain behaviour | `localhost` has no subdomains by default | use `shop.localhost` and `www.localhost` — Chrome resolves them automatically |
| Anything Google fetches server-side | Google's crawler can't reach you: GTM's "is the container installed?" check, PageSpeed Insights, Search Console, Tag Assistant's URL-based remote debug | ngrok/Cloudflare Tunnel, or LocalWP's "Live Link" |
| `SameSite=None; Secure` third-party cookie scenarios | requires HTTPS | run a local TLS proxy, or accept the difference |
| Server-side GTM | needs a tagging server endpoint | sGTM runs fine as another Docker container if you want to go there |

None of these affect anything in this lab.

### 2.7 Consent Mode

If you later install a consent plugin (CMP), GA4 tags will start respecting
`analytics_storage`. Denied means cookieless pings with no client ID — which looks exactly like
"traffic collapsed overnight". `/lab-edge/` §6 lets you toggle it and watch the hit shape change.
For a lab, install no CMP at all.

---

## 3. GTM on localhost

### 3.1 Preview mode

Works fine. In GTM click **Preview**, and enter `http://localhost:8888/` as the URL. Tag Assistant
opens your site in a new tab with a debug session attached.

**The one thing that breaks it:** Tag Assistant's debug session rides on third-party cookies. With
Chrome's third-party cookie blocking on, Preview connects and then immediately disconnects, or
never leaves "Connecting…". Allow third-party cookies for:

```
[*.]tagassistant.google.com
[*.]googletagmanager.com
```

(Chrome → Settings → Privacy and security → Third-party cookies → *Sites allowed to use
third-party cookies*.) This is the number one GTM Preview support issue and the error message
tells you nothing.

Secondary causes: an ad blocker on the Tag Assistant tab, or `WP_HOME` not matching the URL you
typed (a redirect from `localhost:8888` to `localhost` drops the debug parameter).

### 3.2 GTM Environments — publish nothing, test everything

Rather than publishing to Live every time you want to test on localhost, create a **Development**
environment (GTM → Admin → Environments), grab its snippet, and copy the two query parameters into
`.env`:

```bash
GTM_ENV_AUTH=aBcDeF...
GTM_ENV_PREVIEW=env-5
```

The plugin appends them to the `gtm.js` URL. Localhost then always loads the latest *draft* of your
container while production stays on Live. This is the single best workflow improvement available
and almost nobody uses it.

### 3.3 WSL2 note

The stack runs in WSL2; your browser is on Windows. WSL2's `localhostForwarding` means
`http://localhost:8888` works unmodified from Windows. If it ever doesn't (it can break after a
Windows update or a VPN client installs a conflicting adapter), get the WSL IP with
`ip addr show eth0` and use that instead — but remember that changes the origin, so update
`WP_HOME`/`WP_SITEURL` in `docker-compose.yml` to match or WordPress will redirect you back.

Also: extensions live in the Windows browser, so the blocked-request diagnosis in §2.4 applies
there, not in WSL.

---

## 4. How the lab is wired

```
docker-compose.yml                       wordpress:6-php8.3-apache + mariadb:11 + adminer
.env                                     GTM / GA4 IDs, port, GTM environment params
Makefile                                 up / down / bootstrap / logs / reset / urls
scripts/bootstrap.sh                     idempotent WP install + page creation via WP-CLI
wp-content/mu-plugins/
  gtm-datalayer-lab.php                  head ordering, tag injection, shortcodes, settings, AJAX
  gtm-lab/sections.php                   all demo markup, annotated inline
  gtm-lab/assets/inspector.js            dataLayer + GA4 hit capture, panel UI
  gtm-lab/assets/lab.js                  the data-dl-* behaviour layer
  gtm-lab/assets/lab.css
```

It's a **must-use plugin**, so it loads on every request and can't be accidentally deactivated.
Only top-level `.php` files in `mu-plugins/` autoload, which is why `sections.php` and the assets
sit in a subdirectory.

### 4.1 The `<head>` order, which is the whole ball game

```
wp_head priority 1   window.dataLayer = []  +  page/user context push
wp_head priority 2   inspector.js   (blocking <script src>, deliberately not enqueued)
wp_head priority 3   GTM container snippet
wp_body_open         GTM <noscript> iframe
footer, deferred     lab.css + lab.js
```

Verify it any time with:

```bash
curl -s http://localhost:8888/lab-clicks/ | grep -nE 'window.dataLayer|inspector\.js|gtm\.js'
```

Why it matters: a push that happens *after* `gtm.js` still gets processed — GTM replays the queue —
but any Data Layer Variable read during the container's initial Page View evaluation will be
`undefined`. That is the classic "my custom dimension is empty on page_view but fine on every
other event" bug, and it is always this.

The inspector is a plain blocking `<script src>` rather than a `wp_enqueue_script` call for the
same reason: enqueued scripts print in dependency order relative to *each other*, not relative to
raw `wp_head` output, and the inspector must wrap `dataLayer.push`, `sendBeacon` and `fetch` while
they are still native.

### 4.2 Declarative instrumentation

`sections.php` is markup only. `lab.js` reads attributes:

| Attribute | Behaviour |
|---|---|
| `data-dl-click="name"` | delegated click push (survives DOM injection) |
| `data-dl-hover="name"` | `mouseenter` + dwell threshold (`data-dl-hover-dwell` to override) |
| `data-dl-visible="name"` | IntersectionObserver at 50% (`data-dl-visible-once="0"` to repeat) |
| `data-dl-input="field"` | focus / debounced input / change / blur, **never the value** |
| `data-dl-scroll="50"` | vertical milestone |
| `data-dl-params='{…}'` | extra params merged into the push |
| `data-dl-dedupe`, `-prevent`, `-navigate`, `-delay`, `-callback` | the click-behaviour demos |

Add a test case by adding markup. No JS changes needed.

---

## 5. What to build in GTM

The lab pushes events; you build the tags. Suggested minimum to make it interesting:

**Variables** (Data Layer Variable, Version 2)
`page.type`, `page.post_id`, `user.logged_in`, `user.role`, `form_id`, `field_name`,
`value_length_bucket`, `scroll_threshold`, `cta_id`, `item_list_id`, `ecommerce`

**Triggers**

| Trigger | Type | Config | Page |
|---|---|---|---|
| CTA click | Custom Event | `cta_click` | clicks |
| All button clicks | All Elements | Click Element **matches CSS selector** `.dllab-btn, .dllab-btn *` | clicks |
| Outbound | Just Links | Click URL does not contain `localhost` | clicks |
| Hover | Custom Event | `card_hover` | hover |
| Promo impression | Element Visibility | CSS selector `.dllab-promo`, 50%, once per element | hover |
| Scroll | Scroll Depth | Vertical 25/50/75/90 | scroll |
| Field engagement | Custom Event | `form_field_.*`, use **matches RegEx** | forms |
| Lead | Custom Event | `generate_lead` | forms |
| Thank-you fallback | Element Visibility | `#dllab-ajax-success` | forms |
| Virtual pageview | Custom Event | `virtual_page_view` | spa |
| History (for contrast) | History Change | all | spa |
| Ecommerce | Custom Event | `view_item_list\|select_item\|view_item\|add_to_cart\|begin_checkout\|purchase` | ecommerce |
| JS error | JavaScript Error | all | edge |

The instructive pairs are: *All Elements with the wrong selector* vs the right one (§6.2), and
*History Change* vs `virtual_page_view` (§6.7).

I can generate a ready-to-import GTM container JSON with all of this pre-built if you'd rather not
click through it — say the word.

---

## 6. Pitfalls

### dataLayer & GTM

**6.1 The dataLayer is a message queue, not a state object.** Once `gtm.js` loads it *replaces*
`dataLayer.push`. Typing `dataLayer` in the console shows you the raw history of messages, not what
your variables resolve to. For the actual state:

```js
google_tag_manager['GTM-XXXXXXX'].dataLayer.get('page.type')
```

The panel's **Model** tab does this for every key it has seen.

**6.2 `{{Click Element}}` is the deepest element under the pointer.** Click a button whose label is
in a `<span>` and `Click Classes` is the span's classes, not the button's. A trigger of
`Click Classes contains dllab-btn` silently never fires. Always use
`Click Element matches CSS selector` with `.btn, .btn *`. `/lab-clicks/` §1 has both variants side
by side.

**6.3 The data model merges; it does not replace.** Push `{cart:{items:3,coupon:"X"}}` then
`{cart:{items:1}}` and `cart.coupon` is still `"X"`. Arrays merge *index by index*, so a 3-item
`view_item_list` followed by a 1-item `add_to_cart` leaves you with three items where item 0 is a
hybrid of both. This is why Google's own GA4 snippets all begin with:

```js
dataLayer.push({ ecommerce: null });
```

`/lab-ecommerce/` has a button that reproduces the bug so you can see it in the Model tab.

**6.4 There is no hover trigger in GTM.** Built-ins are click, link click, form, scroll, element
visibility, history, timer, JS error, YouTube, and custom event. Hover must be a custom event you
push yourself — and it needs a dwell threshold or a mouse crossing the page generates dozens of
events. `/lab-hover/` §2 counts `mouseover` vs `mouseenter` so you can see the difference; a sweep
across six children fires `mouseover` seven times and `mouseenter` once.

**6.5 Scroll Depth only watches the window.** Inner `overflow: auto` containers — modals, long
sidebars, chat panels — produce nothing. It also fires once per threshold *per page load* and does
not reset on SPA route changes, so your second virtual pageview always looks like nobody scrolled.
`/lab-scroll/` §2–3.

**6.6 Form Submission is the least reliable built-in trigger.** It hooks the `submit` event, which
means it misses:

- AJAX forms — the submit fires before the server has said yes, so you count failures as leads
- `form.submit()` called from JS — bypasses listeners entirely, by spec
- `<button type="button">` with a click handler — there is no submit event at all
- forms in iframes (Typeform, HubSpot, Calendly…)

And it *over*-fires when a plugin validates in JS after the submit event. The reliable pattern is
**Element Visibility on the success message**, plus a `generate_lead` push on the AJAX response.
`/lab-forms/` §2–4 demonstrates all four failure modes.

**6.7 History Change fires before your app has rendered.** A GA4 page_view triggered on History
Change usually sends the *previous* page's `document.title`. Trigger on your own
`virtual_page_view` pushed after the route settles. `/lab-spa/` has an 800ms render-delay toggle
that makes the lag visible.

**6.8 GTM cannot see into iframes or shadow DOM.** Same-origin iframes included — the container
lives in one document only. Open shadow roots retarget events to the host element, so your
selectors miss; closed roots leak nothing. `postMessage` is the escape hatch, demonstrated on
`/lab-edge/`.

**6.9 `eventCallback` cannot come from server-rendered HTML.** It's a function, so it can't survive
`wp_json_encode`. Push it from JavaScript, and always pair it with `eventTimeout` or a hung tag
blocks the navigation forever.

**6.10 The JavaScript Error trigger uses `window.onerror`.** Unhandled promise rejections do not
fire it. Both buttons are on `/lab-edge/` §4.

### GA4

**6.11 Enhanced Measurement will duplicate your custom tags.** It auto-collects `scroll` (at 90%
only), `click` (outbound), `file_download`, `video_*`, `form_start`, `form_submit` and
`view_search_results`. Build a custom scroll tag and you get *both* — with different parameters, so
they don't even aggregate. Decide per event: Enhanced Measurement or custom, never both. Toggle
them in Admin → Data streams → your stream → Enhanced measurement → gear icon.

WordPress note: GA4's site-search detection looks for `q, s, search, query, keyword`. WordPress uses
`?s=`, which is in the default list — so site search usually works with zero configuration. Check
before you build a tag for it.

**6.12 "It's in DebugView but not in my reports."** Almost always one of:

- Custom parameters only appear in standard reports once **registered as a custom dimension**
  (Admin → Custom definitions), and only for data collected *after* registration. They appear in
  DebugView and BigQuery immediately regardless.
- 24–48 hour processing.
- Cardinality — high-unique-value dimensions collapse into `(other)`.
- You are looking at a different property.

**6.13 Limits, all silent.** Event name 40 chars; parameter name 40 chars; parameter *value* 100
chars; 25 parameters per event; 200 items per ecommerce event; 50 event-scoped custom dimensions,
25 user-scoped, 25 item-scoped per property. Nothing warns you — values are just truncated or
dropped. `/lab-edge/` §7 pushes an oversized event so you can compare the panel against DebugView.

**6.14 Event names are case-sensitive.** `Purchase` and `purchase` are two different events, and
only one of them populates the ecommerce reports. Reserved prefixes: `google_`, `ga_`, `firebase_`
(and `gtm.` inside the dataLayer).

**6.15 Types matter.** `value: "24.00"` is a string and GA4 discards it from revenue reporting.
`currency` is required whenever `value` is set. `/lab-ecommerce/` §3 has a deliberately broken push
for comparison.

**6.16 `transaction_id` must be unique.** GA4 de-duplicates repeat purchases with the same ID,
which is what you want for a refreshed thank-you page — and exactly what silently eats your test
purchases when you reuse `TEST-123`. The lab generates a fresh one each time.

### WordPress

**6.17 `WP_HOME`/`WP_SITEURL` must match the port**, or you get a redirect loop and GTM Preview
loses its debug parameter. Both are pinned in `docker-compose.yml`.

**6.18 `WP_DEBUG_DISPLAY` must be `false`.** A PHP notice printed before the doctype lands *inside*
your dataLayer script block and takes the whole page's JS down. Logging is on; display is off; tail
it with `make debuglog`.

**6.19 Caching, minification and "optimisation" plugins.** Autoptimize, WP Rocket, LiteSpeed,
Cloudflare Rocket Loader and Jetpack Boost all defer or concatenate scripts, which reorders your
dataLayer relative to `gtm.js` and reintroduces §4.1. Cloudflare Rocket Loader in particular turns
every inline script async. **Install none of them here.** In production, exclude the GTM snippet
and the dataLayer block explicitly.

**6.20 `wpautop` and `wptexturize` will mangle shortcode output.** `wpautop` inserts stray `<p>`
tags mid-markup; `wptexturize` converts straight quotes to curly ones, which breaks
`data-dl-params='{"a":"b"}'` and any inline JS. The plugin disables both on lab pages. The general
rule: **never put inline JavaScript in shortcode or block-editor output** — always enqueue a file.

**6.21 The `<noscript>` pixel needs `wp_body_open`.** Themes predating WP 5.2 may not call it, in
which case the pixel silently never renders. The bootstrap activates a block theme so it works.

**6.22 GTM4WP is the production answer.** DuracellTomi's *GTM for WordPress* gives you WooCommerce
ecommerce, post metadata and user data in the dataLayer for free, and it's what you'd actually ship.
It's not used here because a hand-rolled plugin makes the ordering and payload shape visible, which
is the point of a lab. Its main gotcha: it has its own placement setting, and "codeless injection"
can land the snippet after other output.

### Privacy — the one that's actually serious

**6.23 Never push a value a user typed.** Email addresses, names, phone numbers and free-text are
PII. Sending them to GA4 breaches the Google Analytics terms of service, and enforcement means
losing the property's data — you cannot surgically delete one parameter.

Push the *shape*: field name, type, length bucket, validity, whether it was completed. That answers
"where do people abandon this form?" without touching content. The lab's form instrumentation
pushes `value_length` and `value_length_bucket` and never `value` — except for selects, radios and
checkboxes, where the chosen option is a value *you* authored, not one the user typed.

Same applies to `user_id`: a WordPress user ID is fine as a GA4 `user_id` (it's a pseudonymous
internal key), but an email address or username is not. The lab pushes `user.role` and
`user.logged_in` only.

---

## 7. Hacks and tooling

**Read GA4 hits in DevTools without an extension.** Network tab, filter `collect`. The payload is
querystring-encoded:

| Param | Meaning |
|---|---|
| `en` | event name |
| `tid` | measurement ID |
| `cid` / `sid` | client ID / session ID |
| `ep.foo` | string event parameter |
| `epn.foo` | numeric event parameter |
| `up.foo` / `upn.foo` | user property |
| `_et` | engagement time (ms) |
| `_dbg=1` | debug mode on |

Batched hits are POSTs with one event per body line. The inspector's **GA4 hits** tab decodes all
of this for you, and **copy** puts it on the clipboard as JSON.

**Dump the merged model:**
```js
copy(window.__DLLAB_INSPECTOR__.model())
```

**Watch every push live:**
```js
window.__DLLAB_INSPECTOR__.pushes().map(p => p.args[0])
```

**Extensions worth having** (in the dedicated profile): *Tag Assistant Companion* (required for
Preview), *Google Analytics Debugger*, and David Vallejo's *Analytics Debugger* — the last is the
best of the three for GA4 payload inspection.

**Test without a browser** using the Measurement Protocol, useful for CI:
```bash
curl -s -X POST "https://www.google-analytics.com/debug/mp/collect?measurement_id=G-XXXX&api_secret=YOUR_SECRET" \
  -d '{"client_id":"test.123","events":[{"name":"lab_ping","params":{"debug_mode":true}}]}'
```
The `/debug/` path validates and returns errors instead of silently accepting; drop `/debug` to
actually send. Get the API secret from Admin → Data streams → Measurement Protocol API secrets.

**WP-CLI without installing it:**
```bash
make wp CMD="option get home"
```

**Reset everything** (destroys the database):
```bash
make reset
```

**Fake a second domain for cross-domain testing** — add to the **Windows** hosts file
(`C:\Windows\System32\drivers\etc\hosts`, as Administrator):
```
127.0.0.1 shop.test
127.0.0.1 www.test
```
Then set `WP_HOME` accordingly. `*.localhost` needs no hosts entry at all in Chrome.

**LocalWP as an alternative** to this Docker stack: one-click HTTPS and a "Live Link" ngrok tunnel,
which is the fastest way to get a public URL for real-mobile testing or for anything Google needs
to fetch server-side (§2.6). Worth having installed alongside.

---

## 7b. The static build

```bash
make static     # mirrors the running site into docs/
make preview    # serve it at http://localhost:8090
```

`docs/` is committed and GitHub Pages serves it from **Settings → Pages → Deploy from a branch →
`main` / `/docs`**. Three things the exporter has to deal with, all of which bite silently:

- **`-e robots=off` is load-bearing.** `bootstrap.sh` sets `blog_public=0`, so WordPress emits
  `<meta name="robots" content="noindex, nofollow">`, and wget honours meta-robots `nofollow` by
  default. Without the flag it fetches `index.html` and then follows nothing at all — not even
  stylesheets — and exits successfully.
- **Cache-busted assets become `?`-in-filename.** wget saves `lab.css?ver=123` literally; Git
  tolerates it, Pages does not, because the browser percent-encodes the `?` and 404s. The exporter
  renames them and strips the query from every reference — including the `%3F` form that
  `--convert-links` produces.
- **WordPress shortlinks hijack internal navigation.** wget names a downloaded file after the URL
  it *requested*, not the one it was redirected to, so fetching `/?p=5` produces a stub that
  `--convert-links` then points every pretty `/lab-clicks/` link at. Rejecting `[?&]p=` fixes it.

The export ends with a link check that walks every `href`/`src` in the output and fails the build
if one does not resolve on disk. Given how many ways the above can go wrong, treat it as the actual
test.

Not exported: `/wp-admin`, WordPress search (`?s=` returns the homepage), the REST API, and the
`admin-ajax.php` endpoint — `submitLead()` in `lab.js` detects the blank `ajaxUrl` and simulates
the round trip instead.

---

## 8. Troubleshooting

| Symptom | Cause |
|---|---|
| Push appears in panel, no GA4 hit | Ad blocker, or no GA4 tag is firing — check GTM Preview |
| No pushes at all | JS error earlier on the page; check the console |
| GA4 hit sent, nothing in DebugView | Wrong measurement ID; or no `debug_mode`; or wrong device selected in DebugView |
| DebugView fine, Realtime empty | Data filter is Active and excluding you (that's §2.1 working) |
| Realtime fine, reports empty | 24–48h, or unregistered custom dimension |
| GTM Preview won't connect | Third-party cookies blocked for `tagassistant.google.com` (§3.1) |
| Variables `undefined` on Page View only | Push order — §4.1 |
| Every event fires twice | Enhanced Measurement + a custom tag for the same thing (§6.11) |
| Redirect loop / infinite `wp-admin` redirect | `WP_HOME`/`WP_SITEURL` vs actual port |
| Stray `<p>` tags in the demo markup | `wpautop` re-enabled (§6.20) |
| WordPress won't start | `docker compose logs wordpress`; check port 8888 is still free |

---

## 9. Suggested order to work through it

1. `/lab-clicks/` with GTM Preview open. Build the wrong selector trigger first, watch it not fire,
   then fix it. That single exercise explains more than any documentation.
2. `/lab-forms/`. Get `generate_lead` firing on the AJAX response, then add the Element Visibility
   fallback and confirm both fire exactly once.
3. `/lab-ecommerce/`. Press the merge-bug button, then look at the Model tab.
4. `/lab-spa/`. Build a page_view on History Change, see the wrong title, move it to
   `virtual_page_view`.
5. `/lab-edge/` last — it's the reference page for when something in production doesn't make sense.

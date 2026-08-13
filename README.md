# dataLayer Lab

A local WordPress instance wired for Google Tag Manager and GA4, with seven pages of
deliberately instrumented UI — clicks, hover, scroll, text input, SPA routing, ecommerce — plus a
page of things that are supposed to break.

It exists to make measurement behaviour *visible*: what actually lands in `window.dataLayer`, what
GTM's merged data model resolves that to, and what finally leaves the browser as a GA4 hit. When
those three disagree, the disagreement is the lesson.

**No GTM container is required.** The on-page inspector hooks `dataLayer.push` before anything else
loads, so every push, every behaviour and every JavaScript-level lesson works standalone. Adding a
container ID lights up the merged-model and GA4-hit views on top.

📖 **[SETUP.md](SETUP.md)** — full setup, the localhost/GA4 question answered properly, and 23
numbered pitfalls with the lab page that reproduces each one.

---

## Quick start

```bash
cp .env.example .env && docker compose --profile tools up -d && ./scripts/bootstrap.sh
```

| | |
|---|---|
| Site | <http://localhost:8888/> |
| Admin | <http://localhost:8888/wp-admin/> — `admin` / `admin` |
| Adminer | <http://localhost:8081/> — server `db`, user/pass `wordpress` |

Requires Docker and Docker Compose. Ports are configurable in `.env` (the defaults avoid 80, 8000,
8080 and 3306, which are commonly already taken).

To point it at a real container, put your ID in `.env` and restart:

```bash
docker compose up -d wordpress
```

`make help` lists everything else.

## The pages

| Page | What it exercises |
|---|---|
| `/` | Index and warm-up pushes |
| `/lab-clicks/` | Nested click targets, outbound/mailto/tel/download links, the navigation race, late-injected DOM |
| `/lab-hover/` | Dwell thresholds, `mouseover` vs `mouseenter`, Element Visibility impressions |
| `/lab-scroll/` | Page milestones, inner overflow containers, horizontal carousels |
| `/lab-forms/` | Field engagement, validation failure, native vs `form.submit()` vs AJAX, PII hazards |
| `/lab-spa/` | `pushState` routing, hashchange, virtual pageviews, the render-lag trap |
| `/lab-ecommerce/` | The GA4 item funnel, and a live reproduction of the `ecommerce: null` merge bug |
| `/lab-edge/` | Shadow DOM, iframes, JS errors, `eventCallback`, Consent Mode, naming limits |

## The inspector

Bottom-right of every page, draggable, three tabs:

- **Pushes** — every `dataLayer.push` in order, including those that happened before the container
  loaded.
- **GA4 hits** — decoded `/g/collect` requests with `ep.`/`epn.` parameters unpacked. It wraps
  `sendBeacon`, `fetch` and `XHR` while they are still native, so no extension is needed.
- **Model** — the *merged* GTM data model, which is what your variables actually resolve to. Not
  the same thing as the Pushes tab, and the difference is the point.

Disable it under Settings → dataLayer Lab. `window.__DLLAB_INSPECTOR__` exposes the same data to
the console.

## Static build (GitHub Pages)

Everything that teaches something is client-side, so the lab exports to a static site. The only
casualty is the AJAX form endpoint, which `lab.js` detects and simulates — the measurement lesson
(push the conversion on the *response*, not the submit) is unchanged.

```bash
make up && make static   # writes docs/
make preview             # serve it at http://localhost:8090
```

`docs/` is committed. To publish: **Settings → Pages → Deploy from a branch → `main` / `/docs`**.
The export uses relative paths throughout, so it works at a project-site subpath (`/<repo>/`) as
well as at a domain root, and it ships `robots.txt` with `Disallow: /` plus a `noindex` meta tag —
an indexed measurement lab means strangers generating sessions in whatever property it points at.

A public HTTPS origin is worth having alongside the local instance: it is the only way to test real
`SameSite=None; Secure` cookie behaviour, and the only way Google's own tools (GTM's
container-installed check, Tag Assistant's URL debug, PageSpeed) can reach the site at all.

What the static build cannot show you is the WordPress half — `wp_head` priority ordering,
`wpautop` mangling shortcode output, mu-plugin loading, `wp_body_open`. Those are pitfalls
§6.17–6.22 in [SETUP.md](SETUP.md), and they need the real instance.

## Layout

```
docker-compose.yml              WordPress + MariaDB + Adminer
.env.example                    GTM / GA4 IDs, port, GTM environment params
Makefile                        up / down / bootstrap / static / preview / reset
scripts/bootstrap.sh            idempotent WP install + page creation via WP-CLI
scripts/export-static.sh        mirror + rewrite + link-check into docs/
wp-content/mu-plugins/
  gtm-datalayer-lab.php         head ordering, tag injection, shortcodes, settings
  gtm-lab/sections.php          all demo markup, annotated inline
  gtm-lab/assets/inspector.js   dataLayer + GA4 hit capture, panel UI
  gtm-lab/assets/lab.js         the data-dl-* behaviour layer
docs/                           static build output (committed, GitHub Pages)
```

Instrumentation is declarative — `sections.php` is markup only, and `lab.js` reads `data-dl-*`
attributes off it. Add a test case by adding markup; no JavaScript changes needed.

## Licence

MIT — see [LICENSE](LICENSE).

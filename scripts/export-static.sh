#!/usr/bin/env bash
# Mirror the running WordPress lab into ./docs/ as a self-contained static site
# suitable for GitHub Pages.
#
# Everything in the lab that teaches something is client-side, so the export
# loses only the WordPress layer itself: no admin, no search, no admin-ajax.
# The AJAX form demo detects the missing endpoint and simulates the round trip
# (see submitLead() in lab.js).
set -euo pipefail

cd "$(dirname "$0")/.."

PORT="${WP_PORT:-8888}"
[[ -f .env ]] && { set -a; source .env; set +a; PORT="${WP_PORT:-8888}"; }
SRC="http://localhost:${PORT}"
OUT="docs"

command -v wget >/dev/null || { echo "wget is required"; exit 1; }

curl -fsS -o /dev/null "${SRC}/" || {
	echo "The lab isn't running at ${SRC} — start it with 'make up' first."; exit 1; }

echo "→ mirroring ${SRC} into ${OUT}/"
rm -rf "${OUT}"
mkdir -p "${OUT}"

# --page-requisites pulls CSS/JS/images; --convert-links rewrites what it fetched
# to relative paths, which is what makes a project-site subpath (/<repo>/) work.
# [?&]p= rejects WordPress's shortlinks (/?p=5). Without it wget fetches them,
# names the file after the *requested* URL rather than the /lab-clicks/ they
# redirect to, and then rewrites every pretty internal link to that stub.
REJECT='(wp-admin|wp-login|wp-json|xmlrpc|/feed/|[?&]s=|[?&]p=)'

# -e robots=off is load-bearing: bootstrap.sh sets blog_public=0, so WordPress
# emits <meta name="robots" content="noindex, nofollow">, and wget honours
# meta-robots nofollow by default — it will fetch index.html and then follow
# nothing at all, not even stylesheets, with no error message.
wget \
	--quiet --show-progress \
	-e robots=off \
	--mirror \
	--page-requisites \
	--convert-links \
	--adjust-extension \
	--no-host-directories \
	--no-parent \
	--reject-regex "${REJECT}" \
	--trust-server-names \
	--directory-prefix="${OUT}" \
	"${SRC}/" || true

# wget exits non-zero when any single requisite 404s (WordPress emits a few
# links that only resolve for logged-in users), so verify by looking at output.
[[ -f "${OUT}/index.html" ]] || { echo "Mirror failed — no index.html produced."; exit 1; }

echo "→ post-processing"
python3 - "$OUT" "$SRC" <<'PY'
import os, re, sys

out, src = sys.argv[1], sys.argv[2]
changed = 0

# WordPress cache-busts assets with ?ver=/?v=, and wget saves those as literal
# filenames containing '?'. Git tolerates that; GitHub Pages does not — the
# browser percent-encodes the '?' and gets a 404. Rename to the bare name and
# strip the query from every reference below.
renamed = 0
for root, _dirs, files in os.walk(out):
    for name in files:
        if '?' not in name:
            continue
        bare = name.split('?', 1)[0]
        src_path, dst_path = os.path.join(root, name), os.path.join(root, bare)
        if not os.path.exists(dst_path):
            os.rename(src_path, dst_path)
            renamed += 1
        else:
            os.remove(src_path)
print(f"   renamed {renamed} cache-busted assets")

# --convert-links percent-encodes the '?' it leaves in references, and
# --adjust-extension may append a second extension, so 'lab.css?ver=1' becomes
# 'lab.css%3Fver=1.css'. Match both forms and everything trailing.
QUERY = re.compile(
    r'(\.(?:css|js|woff2?|ttf|png|jpe?g|gif|svg|webp|pdf|ico))(?:\?|%3F)[^"\'\s)>]*',
    re.I,
)

# WordPress emits several <link> tags that only resolve against a live install.
# Matching on tag content rather than exact attribute order — the order is not
# guaranteed and brittle patterns here fail silently.
LINK_TAG = re.compile(r'<link\b[^>]*>', re.I)
DEAD_MARKERS = ('wp-json', 'xmlrpc.php', 'wlwmanifest', 'oembed', 'application/rss+xml')
DEAD_RELS = re.compile(r'rel=[\'"](?:EditURI|shortlink|https://api\.w\.org/)[\'"]', re.I)

def drop_dead_links(text):
    def repl(match):
        tag = match.group(0)
        low = tag.lower()
        if any(marker in low for marker in DEAD_MARKERS) or DEAD_RELS.search(tag):
            return ''
        return tag
    return LINK_TAG.sub(repl, text)

for root, _dirs, files in os.walk(out):
    for name in files:
        if not name.endswith(('.html', '.css', '.js')):
            continue
        path = os.path.join(root, name)
        with open(path, encoding='utf-8', errors='surrogateescape') as fh:
            text = original = fh.read()

        # Depth of this file relative to the site root, so absolute URLs that
        # wget left behind become correct relative ones under /<repo>/.
        depth = os.path.relpath(root, out).count(os.sep) + 1 if os.path.relpath(root, out) != '.' else 0
        prefix = '../' * depth if depth else './'

        text = text.replace(src + '/', prefix).replace(src, prefix)
        text = QUERY.sub(r'\1', text)
        # wget HTML-encodes the '&' joining query params before --convert-links runs.
        text = text.replace('&#038;ver=', '').replace('&amp;ver=', '')

        if name.endswith('.html'):
            text = drop_dead_links(text)
            # No PHP on a static host: blank the endpoint so lab.js simulates it.
            text = re.sub(r'("ajaxUrl":")[^"]*(")', r'\1\2', text)
            text = re.sub(r'("nonce":")[^"]*(")', r'\1static\2', text)
            if '<meta name="robots"' not in text:
                text = text.replace('<head>', '<head>\n<meta name="robots" content="noindex, nofollow" />', 1)

        if text != original:
            with open(path, 'w', encoding='utf-8', errors='surrogateescape') as fh:
                fh.write(text)
            changed += 1

print(f"   rewrote {changed} files")
PY

# GitHub Pages runs Jekyll by default, which strips files and dirs beginning
# with an underscore. WordPress doesn't emit any, but the marker costs nothing.
touch "${OUT}/.nojekyll"

# -- Markdown Viewers for the per-CMS Custom JS Examples --
# The WordPress page keeps the unqualified filename: it was published first and
# the URL is already out in the world.
CMS_PAGES=(
	"custom-js-examples|WP_GTM_CUSTOM_JS_EXAMPLES.md|WordPress"
	"custom-js-examples-drupal|DRUPAL_GTM_CUSTOM_JS_EXAMPLES.md|Drupal"
	"custom-js-examples-joomla|JOOMLA_GTM_CUSTOM_JS_EXAMPLES.md|Joomla"
	"custom-js-examples-magento|MAGENTO_GTM_CUSTOM_JS_EXAMPLES.md|Magento"
	"custom-js-examples-ghost|GHOST_GTM_CUSTOM_JS_EXAMPLES.md|Ghost"
)

for entry in "${CMS_PAGES[@]}"; do
	IFS='|' read -r slug md label <<< "${entry}"
	cp "${md}" "${OUT}/"

	# The same bar the lab pages carry in their header, with the current platform
	# rendered as a filled pill rather than a link.
	switcher=""
	for other in "${CMS_PAGES[@]}"; do
		IFS='|' read -r oslug omd olabel <<< "${other}"
		if [[ "${oslug}" == "${slug}" ]]; then
			switcher+="
                <span class=\"dllab-cmsbar-current\" aria-current=\"page\">${olabel}</span>"
		else
			switcher+="
                <a href=\"${oslug}.html\">${olabel}</a>"
		fi
	done

	cat > "${OUT}/${slug}.html" <<EOF
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>${label} Custom JS Examples - dataLayer Lab</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow" />
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/github-markdown-css/5.2.0/github-markdown.min.css">
    <style>
        body { box-sizing: border-box; min-width: 200px; max-width: 980px; margin: 0 auto; padding: 45px; background: #fff; }
        @media (max-width: 767px) { body { padding: 15px; } }
        .back-link { display: inline-block; margin-bottom: 20px; text-decoration: none; font-weight: bold; }
        /* The same bar the lab pages carry, inlined: these viewers are bare
           github-markdown pages and never load lab.css. Boxed rather than
           borderless, because here there is no site header to hang off. */
        .dllab-cmsbar {
            --bar-fg: #e3eaf2; --bar-mut: #9fb0c4; --bar-line: #2b3644; --bar-accent: #6fb4ff;
            background: #131a23; border: 1px solid var(--bar-line); border-radius: 10px;
            padding: .55rem .7rem; margin-bottom: 24px;
        }
        .dllab-cmsbar-inner { display: flex; align-items: center; gap: .4rem 1rem; flex-wrap: wrap; }
        .dllab-cmsbar-label { font-size: .7rem; font-weight: 600; letter-spacing: .07em; text-transform: uppercase; color: var(--bar-mut); }
        .dllab-cmsbar-links { display: flex; flex-wrap: wrap; gap: .35rem; }
        .dllab-cmsbar a, .dllab-cmsbar-current {
            border: 1px solid var(--bar-line); border-radius: 999px;
            padding: .2rem .7rem; font-size: .84rem; line-height: 1.4; text-decoration: none;
        }
        .dllab-cmsbar a { color: var(--bar-fg); }
        .dllab-cmsbar a:hover, .dllab-cmsbar a:focus-visible { border-color: var(--bar-accent); color: var(--bar-accent); }
        .dllab-cmsbar-current { background: var(--bar-line); color: #fff; font-weight: 600; }
    </style>
</head>
<body class="markdown-body">
    <a href="index.html" class="back-link">← Back to dataLayer Lab</a>
    <div class="dllab-cmsbar">
        <nav class="dllab-cmsbar-inner" aria-label="Custom JS examples by platform">
            <span class="dllab-cmsbar-label">Custom JS recipes</span>
            <div class="dllab-cmsbar-links">${switcher}
            </div>
        </nav>
    </div>
    <div id="content">Loading examples...</div>
    <script src="https://cdn.jsdelivr.net/npm/marked/marked.min.js"></script>
    <script>
        fetch('${md}')
            .then(res => res.text())
            .then(text => { document.getElementById('content').innerHTML = marked.parse(text); })
            .catch(err => { document.getElementById('content').innerHTML = 'Error loading markdown.'; });
    </script>
</body>
</html>
EOF
done

# Inject the Custom JS bar into every exported lab page, as a third row inside
# the site header — under the site title and main nav, sharing their left edge.
# Styled by .dllab-cmsbar in lab.css.
python3 - "${OUT}" <<'PY'
import os, re, sys

out = sys.argv[1]
PAGES = [
    ('custom-js-examples.html', 'WordPress'),
    ('custom-js-examples-drupal.html', 'Drupal'),
    ('custom-js-examples-joomla.html', 'Joomla'),
    ('custom-js-examples-magento.html', 'Magento'),
    ('custom-js-examples-ghost.html', 'Ghost'),
]

SITE_HEADER = '<header class="wp-block-template-part">'
# The header's alignwide flex row: site title, then nav, then (now) the bar.
HEADER_COLUMN = re.compile(
    r'<div class="wp-block-group alignwide[^"]*is-content-justification-space-between[^"]*"[^>]*>'
)
DIV_TAG = re.compile(r'<(/?)div\b[^>]*>', re.I)


def close_index(text, start):
    """Index of the </div> closing the <div> that begins at `start`.

    Walking the tag depth rather than counting closing tags by eye: the header
    nests deep enough that a positional guess lands the bar in the wrong
    container, and does so silently.
    """
    depth = 0
    for m in DIV_TAG.finditer(text, start):
        depth += -1 if m.group(1) else 1
        if depth == 0:
            return m.start()
    return -1


def bar(prefix):
    i = '\t\t\t'
    links = ('\n' + i + '\t\t\t').join(
        f'<a href="{prefix}{href}">{label}</a>' for href, label in PAGES
    )
    return (
        f'\n{i}<div class="dllab-cmsbar">\n'
        f'{i}\t<nav class="dllab-cmsbar-inner" aria-label="Custom JS examples by platform">\n'
        f'{i}\t\t<span class="dllab-cmsbar-label">Custom JS recipes</span>\n'
        f'{i}\t\t<div class="dllab-cmsbar-links">\n'
        f'{i}\t\t\t{links}\n'
        f'{i}\t\t</div>\n'
        f'{i}\t</nav>\n'
        f'{i}</div>\n{i}'
    )


injected = 0
for root, _dirs, files in os.walk(out):
    for name in sorted(files):
        if not name.endswith('.html'):
            continue
        path = os.path.join(root, name)
        with open(path, encoding='utf-8', errors='surrogateescape') as fh:
            text = fh.read()

        # The standalone recipe viewers have no site header and carry their own
        # copy of the bar, emitted with the page above.
        if 'wp-site-blocks' not in text:
            continue

        m = HEADER_COLUMN.search(text, text.find(SITE_HEADER))
        if not m:
            print(f"   ! no header column in {os.path.relpath(path, out)}")
            continue
        end = close_index(text, m.start())
        if end < 0:
            print(f"   ! unbalanced header column in {os.path.relpath(path, out)}")
            continue

        # Relative depth, so the links resolve from the nested lab pages.
        rel = os.path.relpath(root, out)
        depth = 0 if rel == '.' else rel.count(os.sep) + 1

        with open(path, 'w', encoding='utf-8', errors='surrogateescape') as fh:
            fh.write(text[:end] + bar('../' * depth) + text[end:])
        injected += 1

print(f"   injected the Custom JS bar into {injected} page(s)")
PY

cat > "${OUT}/robots.txt" <<'EOF'
# Measurement lab — public so that Google's own tools can reach it, but there is
# no reason for it to be indexed, and indexed pages mean strangers generating
# sessions in whatever GA4 property it points at.
User-agent: *
Disallow: /
EOF

[[ -f "${OUT}/404.html" ]] || cp "${OUT}/index.html" "${OUT}/404.html"

# The file_download demo target.
mkdir -p "${OUT}/wp-content/uploads"
cp -f wp-content/uploads/dllab-sample.pdf "${OUT}/wp-content/uploads/" 2>/dev/null || true

echo "→ checking every internal link resolves"
python3 - "$OUT" <<'PY'
import os, re, sys, html, urllib.parse

out = sys.argv[1]
REF = re.compile(r'(?:href|src)=["\']([^"\']+)["\']', re.I)
broken = []

for root, _dirs, files in os.walk(out):
    for name in files:
        if not name.endswith('.html'):
            continue
        page = os.path.join(root, name)
        with open(page, encoding='utf-8', errors='surrogateescape') as fh:
            for ref in REF.findall(fh.read()):
                ref = html.unescape(ref).split('#')[0]
                if not ref or re.match(r'^(https?:|//|mailto:|tel:|data:|javascript:|about:)', ref):
                    continue
                target = urllib.parse.unquote(ref)
                path = os.path.normpath(os.path.join(root, target))
                if os.path.isdir(path):
                    path = os.path.join(path, 'index.html')
                if not os.path.exists(path):
                    broken.append((os.path.relpath(page, out), ref))

if broken:
    print(f"   {len(broken)} BROKEN reference(s):")
    for page, ref in broken[:20]:
        print(f"     {page} -> {ref}")
    sys.exit(1)
print("   all internal references resolve")
PY

echo
echo "   ${OUT}/ is $(du -sh "${OUT}" | cut -f1) across $(find "${OUT}" -type f | wc -l) files"
echo "   preview:  python3 -m http.server -d ${OUT} 8090"
echo

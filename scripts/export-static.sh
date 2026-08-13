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

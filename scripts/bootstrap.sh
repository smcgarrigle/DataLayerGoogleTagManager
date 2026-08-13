#!/usr/bin/env bash
# Install WordPress and create the lab pages. Idempotent — safe to re-run.
set -euo pipefail

cd "$(dirname "$0")/.."

[[ -f .env ]] || { echo "No .env found. Run: cp .env.example .env"; exit 1; }
# shellcheck disable=SC1091
set -a; source .env; set +a

PORT="${WP_PORT:-8888}"
URL="http://localhost:${PORT}"
ADMIN_USER="${WP_ADMIN_USER:-admin}"
ADMIN_PASS="${WP_ADMIN_PASS:-admin}"
ADMIN_EMAIL="${WP_ADMIN_EMAIL:-admin@example.com}"

# 2>/dev/null hides Compose's per-invocation container churn, not WP-CLI's own errors,
# which WP-CLI writes to stdout.
wp() { docker compose run --rm -T wpcli "$@" 2>/dev/null; }

echo "→ waiting for ${URL} …"
for i in $(seq 1 60); do
	if curl -fsS -o /dev/null "${URL}" 2>/dev/null; then break; fi
	[[ $i -eq 60 ]] && { echo "WordPress never came up. Try: docker compose logs wordpress"; exit 1; }
	sleep 2
done

if wp core is-installed 2>/dev/null; then
	echo "→ WordPress already installed"
else
	echo "→ installing WordPress"
	wp core install \
		--url="${URL}" \
		--title="dataLayer Lab" \
		--admin_user="${ADMIN_USER}" \
		--admin_password="${ADMIN_PASS}" \
		--admin_email="${ADMIN_EMAIL}" \
		--skip-email
fi

echo "→ base configuration"
wp option update permalink_structure '/%postname%/'
wp option update blogdescription 'GTM + GA4 measurement lab'
wp option update default_ping_status closed
wp option update default_comment_status closed
wp option update blog_public 0          # discourage indexing; irrelevant on localhost but tidy
wp rewrite flush --hard

# A block theme, so wp_body_open exists for the GTM <noscript> tag.
# (Classic themes predating WP 5.2 may not call it — then the noscript pixel silently
#  never renders, which nobody notices until a no-JS audit.)
for t in twentytwentyfive twentytwentyfour twentytwentythree; do
	if wp theme activate "$t" 2>/dev/null; then break; fi
done

# ---------------------------------------------------------------------------
# Pages
# ---------------------------------------------------------------------------

make_page() {
	local slug="$1" title="$2" shortcode="$3"
	local existing
	existing="$(wp post list --post_type=page --name="${slug}" --field=ID --format=ids | tr -d '\r')"
	local content="<!-- wp:shortcode -->${shortcode}<!-- /wp:shortcode -->"

	if [[ -n "${existing}" ]]; then
		wp post update "${existing}" --post_content="${content}" --post_title="${title}" >/dev/null
		echo "   updated  /${slug}/  (#${existing})"
	else
		wp post create --post_type=page --post_status=publish \
			--post_title="${title}" --post_name="${slug}" --post_content="${content}" >/dev/null
		echo "   created  /${slug}/"
	fi
}

echo "→ removing WordPress default content"
for slug in sample-page hello-world privacy-policy; do
	id="$(wp post list --post_type=page,post --post_status=any --name="${slug}" --field=ID --format=ids | tr -d '\r')"
	[[ -n "${id}" ]] && wp post delete "${id}" --force >/dev/null || true
done

echo "→ lab pages"
make_page "lab-home"      "dataLayer Lab"        '[dl_lab_index]'
make_page "lab-clicks"    "Clicks & Links"       '[dl_lab section="clicks"]'
make_page "lab-hover"     "Hover & Visibility"   '[dl_lab section="hover"]'
make_page "lab-scroll"    "Scroll Depth"         '[dl_lab section="scroll"]'
make_page "lab-forms"     "Forms & Text Input"   '[dl_lab section="forms"]'
make_page "lab-spa"       "SPA & History"        '[dl_lab section="spa"]'
make_page "lab-ecommerce" "GA4 Ecommerce"        '[dl_lab section="ecommerce"]'
make_page "lab-edge"      "Edge Cases & Gotchas" '[dl_lab section="edge"]'

HOME_ID="$(wp post list --post_type=page --name=lab-home --field=ID --format=ids | tr -d '\r')"
wp option update show_on_front page
wp option update page_on_front "${HOME_ID}"

# ---------------------------------------------------------------------------
# A downloadable file for the file_download test
# ---------------------------------------------------------------------------

mkdir -p wp-content/uploads
if [[ ! -f wp-content/uploads/dllab-sample.pdf ]]; then
	printf '%%PDF-1.4\n1 0 obj<</Type/Catalog/Pages 2 0 R>>endobj\n2 0 obj<</Type/Pages/Kids[3 0 R]/Count 1>>endobj\n3 0 obj<</Type/Page/Parent 2 0 R/MediaBox[0 0 300 300]>>endobj\ntrailer<</Root 1 0 R>>\n%%%%EOF\n' \
		> wp-content/uploads/dllab-sample.pdf
	echo "→ wrote wp-content/uploads/dllab-sample.pdf"
fi
chmod -R a+rX wp-content/uploads

# ---------------------------------------------------------------------------

cat <<EOF

  Done.

  Site       ${URL}/
  Admin      ${URL}/wp-admin/   (${ADMIN_USER} / ${ADMIN_PASS})
  Adminer    http://localhost:8081/   (server: db, user: wordpress, pass: wordpress)

  GTM container: ${GTM_CONTAINER_ID:-<not set — edit .env and restart>}
  GA4 stream:    ${GA4_MEASUREMENT_ID:-<not set>}

EOF

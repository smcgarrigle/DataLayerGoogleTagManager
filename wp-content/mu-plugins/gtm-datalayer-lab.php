<?php
/**
 * Plugin Name: dataLayer Lab (GTM + GA4)
 * Description: Local measurement lab. Injects GTM/gtag in a deterministic order, exposes a live
 *              dataLayer + GA4 hit inspector, and renders instrumented demo elements via [dl_lab].
 * Version:     1.0.0
 * Author:      dataLayer Lab
 *
 * Must-use plugin: loads on every request, cannot be deactivated from the admin.
 *
 * @package DataLayerLab
 */

namespace DLLab;

defined( 'ABSPATH' ) || exit;

const VERSION  = '1.0.0';
const OPT_GTM  = 'dllab_gtm_id';
const OPT_GA4  = 'dllab_ga4_id';
const OPT_INSP = 'dllab_inspector';

/** Directory + URL for our bundled assets (mu-plugins has no plugin_dir_url helper that respects subdirs well). */
function asset_url( string $file ): string {
	return WPMU_PLUGIN_URL . '/gtm-lab/assets/' . $file;
}
function asset_path( string $file ): string {
	return WPMU_PLUGIN_DIR . '/gtm-lab/assets/' . $file;
}
function asset_ver( string $file ): string {
	$p = asset_path( $file );
	return file_exists( $p ) ? (string) filemtime( $p ) : VERSION;
}

/** Constants (from docker-compose env) beat options, so the .env file is the source of truth. */
function gtm_id(): string {
	$id = defined( 'GTM_LAB_CONTAINER_ID' ) && GTM_LAB_CONTAINER_ID ? GTM_LAB_CONTAINER_ID : get_option( OPT_GTM, '' );
	return preg_match( '/^GTM-[A-Z0-9]+$/i', (string) $id ) ? strtoupper( $id ) : '';
}
function ga4_id(): string {
	$id = defined( 'GTM_LAB_GA4_ID' ) && GTM_LAB_GA4_ID ? GTM_LAB_GA4_ID : get_option( OPT_GA4, '' );
	return preg_match( '/^G-[A-Z0-9]+$/i', (string) $id ) ? strtoupper( $id ) : '';
}
/**
 * GTM Environments: appends gtm_auth / gtm_preview to the gtm.js URL so this local
 * site always loads a specific (usually Development) container version, without you
 * having to publish to Live. Get the values from GTM → Admin → Environments → Get
 * snippet; they are the query params in the snippet it hands you.
 */
function env_params(): string {
	$auth    = defined( 'GTM_LAB_ENV_AUTH' ) ? (string) GTM_LAB_ENV_AUTH : '';
	$preview = defined( 'GTM_LAB_ENV_PREVIEW' ) ? (string) GTM_LAB_ENV_PREVIEW : '';
	if ( ! $auth || ! $preview ) {
		return '';
	}
	return sprintf(
		'&gtm_auth=%s&gtm_preview=%s&gtm_cookies_win=x',
		rawurlencode( $auth ),
		rawurlencode( $preview )
	);
}

function inspector_enabled(): bool {
	// An unchecked checkbox posts nothing, which options.php stores as '' — so test truthiness.
	return (bool) get_option( OPT_INSP, '1' );
}

require_once WPMU_PLUGIN_DIR . '/gtm-lab/sections.php';

/* -------------------------------------------------------------------------
 * 1. HEAD ORDERING — the single most important thing in this whole plugin.
 *
 *    priority  1 : declare dataLayer + push page-level context
 *    priority  2 : inspector (must wrap push/sendBeacon/fetch BEFORE gtm.js)
 *    priority  3 : GTM container snippet (or gtag.js)
 *
 *    Anything that pushes *after* gtm.js still works (GTM replays the queue),
 *    but Data Layer Variables read at container-load time will be undefined,
 *    which is the classic "my variable is empty on Page View" bug.
 * ---------------------------------------------------------------------- */

add_action( 'wp_head', __NAMESPACE__ . '\\print_datalayer_init', 1 );
add_action( 'wp_head', __NAMESPACE__ . '\\print_inspector', 2 );
add_action( 'wp_head', __NAMESPACE__ . '\\print_tag', 3 );
add_action( 'wp_body_open', __NAMESPACE__ . '\\print_gtm_noscript' );

/**
 * Page-level context, pushed before the container exists.
 * Note the shape: no `event` key, so it becomes ambient state rather than a trigger.
 */
function print_datalayer_init(): void {
	$post = get_queried_object();

	$ctx = array(
		'page' => array(
			'type'      => is_front_page() ? 'home' : ( is_singular() ? get_post_type() : ( is_archive() ? 'archive' : 'other' ) ),
			'title'     => wp_get_document_title(),
			'path'      => wp_parse_url( home_url( add_query_arg( array() ) ), PHP_URL_PATH ) ?: '/',
			'post_id'   => ( $post instanceof \WP_Post ) ? $post->ID : null,
			'template'  => ( $post instanceof \WP_Post ) ? ( get_page_template_slug( $post ) ?: 'default' ) : null,
			'lang'      => get_bloginfo( 'language' ),
			'env'       => 'local',
		),
		'user' => array(
			// Logged-in state is a legitimate dimension. A user *ID* is not — see SETUP.md.
			'logged_in' => is_user_logged_in(),
			'role'      => is_user_logged_in() ? ( wp_get_current_user()->roles[0] ?? 'none' ) : 'guest',
		),
		'lab'  => array(
			'version'      => VERSION,
			'container_id' => gtm_id() ?: null,
		),
	);

	printf(
		"<script>window.dataLayer = window.dataLayer || [];\nwindow.dataLayer.push(%s);</script>\n",
		wp_json_encode( $ctx, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE )
	);
}

/** Plain blocking <script src>, deliberately not enqueued, so ordering is guaranteed. */
function print_inspector(): void {
	if ( ! inspector_enabled() ) {
		return;
	}
	printf(
		'<script src="%s"></script>' . "\n",
		esc_url( add_query_arg( 'v', asset_ver( 'inspector.js' ), asset_url( 'inspector.js' ) ) )
	);
}

function print_tag(): void {
	$gtm = gtm_id();
	$ga4 = ga4_id();

	if ( $gtm ) {
		// Verbatim GTM snippet. Do not "optimise" this — async + the j.parentNode
		// insertion point are what make the queue replay work.
		?>
<!-- Google Tag Manager -->
<script>(function(w,d,s,l,i){w[l]=w[l]||[];w[l].push({'gtm.start':
new Date().getTime(),event:'gtm.js'});var f=d.getElementsByTagName(s)[0],
j=d.createElement(s),dl=l!='dataLayer'?'&l='+l:'';j.async=true;j.src=
'https://www.googletagmanager.com/gtm.js?id='+i+dl+'<?php echo esc_js( env_params() ); ?>';f.parentNode.insertBefore(j,f);
})(window,document,'script','dataLayer','<?php echo esc_js( $gtm ); ?>');</script>
<!-- End Google Tag Manager -->
		<?php
	}

	if ( $ga4 && ! $gtm ) {
		// Direct gtag.js path, for comparing "GTM vs raw gtag" behaviour.
		?>
<script async src="https://www.googletagmanager.com/gtag/js?id=<?php echo esc_attr( $ga4 ); ?>"></script>
<script>
window.dataLayer = window.dataLayer || [];
function gtag(){dataLayer.push(arguments);}
gtag('js', new Date());
gtag('config', '<?php echo esc_js( $ga4 ); ?>', {
  debug_mode: true,        // forces this hit stream into GA4 DebugView
  traffic_type: 'internal' // matches the built-in Internal Traffic data filter
});
</script>
		<?php
	} elseif ( $ga4 && $gtm ) {
		echo "<!-- dataLayer Lab: GA4_MEASUREMENT_ID ignored because GTM_CONTAINER_ID is set (avoids double-counting). -->\n";
	}
}

function print_gtm_noscript(): void {
	$gtm = gtm_id();
	if ( ! $gtm ) {
		return;
	}
	printf(
		'<noscript><iframe src="https://www.googletagmanager.com/ns.html?id=%s" height="0" width="0" style="display:none;visibility:hidden"></iframe></noscript>' . "\n",
		esc_attr( $gtm )
	);
}

/* -------------------------------------------------------------------------
 * 2. FRONT-END ASSETS
 * ---------------------------------------------------------------------- */

add_action( 'wp_enqueue_scripts', __NAMESPACE__ . '\\enqueue_assets' );

function enqueue_assets(): void {
	wp_enqueue_style( 'dllab', asset_url( 'lab.css' ), array(), asset_ver( 'lab.css' ) );

	// Footer + defer: these are *behavioural* listeners, they don't need to beat gtm.js.
	wp_enqueue_script( 'dllab', asset_url( 'lab.js' ), array(), asset_ver( 'lab.js' ), true );
	wp_script_add_data( 'dllab', 'strategy', 'defer' );

	wp_localize_script(
		'dllab',
		'DLLAB_CFG',
		array(
			'ajaxUrl'     => admin_url( 'admin-ajax.php' ),
			'nonce'       => wp_create_nonce( 'dllab' ),
			'gtmId'       => gtm_id(),
			'hoverDwell'  => 300,   // ms an element must be hovered before it counts
			'inputDebounce' => 750, // ms of idle before a text-input event fires
		)
	);
}

/* -------------------------------------------------------------------------
 * 3. SHORTCODES
 * ---------------------------------------------------------------------- */

add_shortcode( 'dl_lab', __NAMESPACE__ . '\\shortcode_lab' );
add_shortcode( 'dl_lab_index', __NAMESPACE__ . '\\shortcode_index' );

function shortcode_lab( $atts ): string {
	$atts    = shortcode_atts( array( 'section' => '' ), $atts, 'dl_lab' );
	$section = sanitize_key( $atts['section'] );
	return Sections\render( $section );
}

function shortcode_index(): string {
	return Sections\render_index();
}

/**
 * Marks lab pages so lab.css can hide the theme's post-title block. The lab
 * renders its own <h1> (see Sections\render), so leaving the theme's in place
 * would give every page two competing top-level headings.
 */
add_filter( 'body_class', __NAMESPACE__ . '\\body_class' );

function body_class( array $classes ): array {
	$post = get_queried_object();
	if ( $post instanceof \WP_Post && str_contains( (string) $post->post_content, '[dl_lab' ) ) {
		$classes[] = 'dllab-page';
	}
	return $classes;
}

/**
 * wpautop + wptexturize will happily insert stray <p> tags into our markup and
 * turn straight quotes in data-* attributes into curly ones. Disable both on
 * pages that use the lab shortcode.
 */
add_filter( 'the_content', __NAMESPACE__ . '\\maybe_disable_autop', 8 );

function maybe_disable_autop( $content ) {
	if ( is_string( $content ) && ( str_contains( $content, '[dl_lab' ) || str_contains( $content, 'dllab-' ) ) ) {
		remove_filter( 'the_content', 'wpautop' );
		remove_filter( 'the_content', 'wptexturize' );
	}
	return $content;
}

/* -------------------------------------------------------------------------
 * 4. AJAX endpoint for the "form submitted without a page unload" demo
 * ---------------------------------------------------------------------- */

add_action( 'wp_ajax_dllab_form', __NAMESPACE__ . '\\ajax_form' );
add_action( 'wp_ajax_nopriv_dllab_form', __NAMESPACE__ . '\\ajax_form' );

function ajax_form(): void {
	check_ajax_referer( 'dllab', 'nonce' );
	usleep( 600000 ); // fake latency so you can watch the race
	wp_send_json_success(
		array(
			'lead_id' => 'lead_' . wp_generate_password( 8, false, false ),
			'status'  => 'received',
		)
	);
}

/* -------------------------------------------------------------------------
 * 5. Tiny settings screen (Settings → dataLayer Lab)
 * ---------------------------------------------------------------------- */

add_action( 'admin_menu', __NAMESPACE__ . '\\admin_menu' );
add_action( 'admin_init', __NAMESPACE__ . '\\admin_init' );

function admin_menu(): void {
	add_options_page( 'dataLayer Lab', 'dataLayer Lab', 'manage_options', 'dllab', __NAMESPACE__ . '\\admin_page' );
}

function admin_init(): void {
	register_setting( 'dllab', OPT_GTM, array( 'sanitize_callback' => 'sanitize_text_field', 'default' => '' ) );
	register_setting( 'dllab', OPT_GA4, array( 'sanitize_callback' => 'sanitize_text_field', 'default' => '' ) );
	register_setting( 'dllab', OPT_INSP, array( 'sanitize_callback' => 'sanitize_text_field', 'default' => '1' ) );
}

function admin_page(): void {
	$locked_gtm = defined( 'GTM_LAB_CONTAINER_ID' ) && GTM_LAB_CONTAINER_ID;
	$locked_ga4 = defined( 'GTM_LAB_GA4_ID' ) && GTM_LAB_GA4_ID;
	?>
	<div class="wrap">
		<h1>dataLayer Lab</h1>
		<form method="post" action="options.php">
			<?php settings_fields( 'dllab' ); ?>
			<table class="form-table" role="presentation">
				<tr>
					<th scope="row"><label for="dllab_gtm">GTM container ID</label></th>
					<td>
						<input name="<?php echo esc_attr( OPT_GTM ); ?>" id="dllab_gtm" type="text"
							value="<?php echo esc_attr( get_option( OPT_GTM, '' ) ); ?>"
							placeholder="GTM-XXXXXXX" class="regular-text" <?php disabled( $locked_gtm ); ?> />
						<p class="description">
							<?php echo $locked_gtm
								? 'Locked by GTM_CONTAINER_ID in .env — currently <code>' . esc_html( gtm_id() ) . '</code>.'
								: 'Leave blank to run the lab with no container. The inspector still works.'; ?>
						</p>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="dllab_ga4">GA4 measurement ID</label></th>
					<td>
						<input name="<?php echo esc_attr( OPT_GA4 ); ?>" id="dllab_ga4" type="text"
							value="<?php echo esc_attr( get_option( OPT_GA4, '' ) ); ?>"
							placeholder="G-XXXXXXXXXX" class="regular-text" <?php disabled( $locked_ga4 ); ?> />
						<p class="description">Only used when <strong>no</strong> GTM container is set. Loads gtag.js with <code>debug_mode:true</code>.</p>
					</td>
				</tr>
				<tr>
					<th scope="row">On-page inspector</th>
					<td>
						<label>
							<input type="checkbox" name="<?php echo esc_attr( OPT_INSP ); ?>" value="1"
								<?php checked( inspector_enabled() ); ?> />
							Show the live dataLayer / GA4 hit panel on the front end
						</label>
					</td>
				</tr>
			</table>
			<?php submit_button(); ?>
		</form>
	</div>
	<?php
}

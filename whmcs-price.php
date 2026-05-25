<?php
/*
 * Plugin Name: WHMCS Price Simple
 * Description: Displays WHMCS product prices via the [whmcs pid="10" bc="1m" currency="1"] shortcode.
 * Version:     1.0.5
 * Requires at least: 5.0
 * Requires PHP:      7.4
 * Author:      Fernando Sandmann
 * License:     GPLv2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: whmcs-price-simple
 * Domain Path: /languages
 *
 * Automatic updates via GitHub Releases + Plugin Update Checker.
 *
 * GitHub token (optional, avoids GitHub API rate-limit errors):
 * Add this line to wp-config.php with your Personal Access Token:
 *   define( 'WHMCS_SIMPLE_GITHUB_TOKEN', 'ghp_yourtoken' );
 * Fine-grained token with Contents: Read-only on whmcs-price-simple is enough.
 */

defined( 'ABSPATH' ) || exit;

define( 'WHMCS_SIMPLE_VERSION', '1.0.5' );

// ── Translations ──────────────────────────────────────────────────────────────
// Loads /languages/whmcs-price-simple-{locale}.mo automatically.
// Spanish variants (es_AR, es_ES, es_MX, …) use whmcs-price-simple-es.mo.
// All other locales fall back to the English strings in the code.
add_action( 'plugins_loaded', function () {
	load_plugin_textdomain(
		'whmcs-price-simple',
		false,
		dirname( plugin_basename( __FILE__ ) ) . '/languages'
	);
} );

// ── Automatic updates from GitHub ────────────────────────────────────────────
require_once plugin_dir_path( __FILE__ ) . 'vendor/plugin-update-checker/load-v5p6.php';
use YahnisElsts\PluginUpdateChecker\v5\PucFactory;

$whmcsSimpleUpdater = PucFactory::buildUpdateChecker(
	'https://github.com/sis-argentina/whmcs-price-simple/',
	__FILE__,
	'whmcs-price-simple'
);

// Token priority: wp-config.php constant > database option.
$_whmcs_gh_token = defined( 'WHMCS_SIMPLE_GITHUB_TOKEN' ) && WHMCS_SIMPLE_GITHUB_TOKEN
	? WHMCS_SIMPLE_GITHUB_TOKEN
	: get_option( 'whmcs_simple_github_token', '' );
if ( ! empty( $_whmcs_gh_token ) ) {
	$whmcsSimpleUpdater->setAuthentication( $_whmcs_gh_token );
}
unset( $_whmcs_gh_token );

// ── "Settings" link on plugins list ──────────────────────────────────────────
add_filter( 'plugin_action_links_' . plugin_basename( __FILE__ ), function ( $links ) {
	$url = admin_url( 'options-general.php?page=whmcs_simple' );
	array_unshift( $links, '<a href="' . esc_url( $url ) . '">' . esc_html__( 'Settings', 'whmcs-price-simple' ) . '</a>' );
	return $links;
} );

// ── Three-layer cache ─────────────────────────────────────────────────────────
// 1. Request cache  — in-memory array, lives for one PHP process.
// 2. Object cache   — Redis/Memcached via wp_cache_get/set (if available).
// 3. Transients     — database, universal fallback.
//
// Invalidation uses versioned keys: bumping whmcs_simple_cache_version makes
// all previous keys unreachable without needing a key inventory.

/** @var array<string,string> */
$whmcs_simple_request_cache = [];

/** @var int|null */
$whmcs_simple_cache_version = null;

function whmcs_simple_cache_version(): int {
	global $whmcs_simple_cache_version;
	if ( null === $whmcs_simple_cache_version ) {
		$whmcs_simple_cache_version = (int) get_option( 'whmcs_simple_cache_version', 1 );
		if ( $whmcs_simple_cache_version < 1 ) {
			$whmcs_simple_cache_version = 1;
		}
	}
	return $whmcs_simple_cache_version;
}

function whmcs_simple_versioned_key( string $key ): string {
	return 'v' . whmcs_simple_cache_version() . '_' . $key;
}

function whmcs_simple_bump_cache(): int {
	global $whmcs_simple_request_cache, $whmcs_simple_cache_version;
	$next = whmcs_simple_cache_version() + 1;
	update_option( 'whmcs_simple_cache_version', $next, false );
	$whmcs_simple_cache_version = $next;
	$whmcs_simple_request_cache = [];
	if ( function_exists( 'wp_cache_flush_group' ) ) {
		wp_cache_flush_group( 'whmcs_simple' );
	}
	return $next;
}

function whmcs_simple_get_cache( string $key ) {
	$vkey = whmcs_simple_versioned_key( $key );
	$hit  = wp_cache_get( $vkey, 'whmcs_simple' );
	if ( false !== $hit ) {
		return $hit;
	}
	return get_transient( $vkey );
}

function whmcs_simple_set_cache( string $key, string $value, int $ttl ): void {
	$vkey = whmcs_simple_versioned_key( $key );
	wp_cache_set( $vkey, $value, 'whmcs_simple', $ttl );
	set_transient( $vkey, $value, $ttl );
}

// ── Settings page ─────────────────────────────────────────────────────────────

add_action( 'admin_menu', function () {
	add_options_page(
		'WHMCS Price Simple',
		'WHMCS Price Simple',
		'manage_options',
		'whmcs_simple',
		'whmcs_simple_settings_page'
	);
} );

function whmcs_simple_settings_page(): void {
	if ( ! current_user_can( 'manage_options' ) ) {
		return;
	}

	$notice_type = '';
	$notice_text = '';

	if ( isset( $_POST['whmcs_simple_purge_cache'] ) && check_admin_referer( 'whmcs_simple_purge' ) ) {
		$new_ver     = whmcs_simple_bump_cache();
		$notice_type = 'success';
		/* translators: %d: new cache version number */
		$notice_text = sprintf( __( 'Cache cleared successfully. Current version: %d.', 'whmcs-price-simple' ), $new_ver );
	}

	if ( isset( $_POST['whmcs_simple_save'] ) && check_admin_referer( 'whmcs_simple_settings' ) ) {
		$url   = esc_url_raw( trim( sanitize_text_field( wp_unslash( $_POST['whmcs_url'] ?? '' ) ) ) );
		$ttl   = max( 60, absint( $_POST['whmcs_ttl'] ?? 3600 ) );
		$token = sanitize_text_field( wp_unslash( $_POST['whmcs_github_token'] ?? '' ) );
		update_option( 'whmcs_simple_url', $url );
		update_option( 'whmcs_simple_ttl', $ttl );
		update_option( 'whmcs_simple_github_token', $token );
		$notice_type = 'success';
		$notice_text = __( 'Settings saved.', 'whmcs-price-simple' );
	}

	$saved_url       = get_option( 'whmcs_simple_url', '' );
	$saved_ttl       = (int) get_option( 'whmcs_simple_ttl', 3600 );
	$saved_token     = get_option( 'whmcs_simple_github_token', '' );
	$token_constant  = defined( 'WHMCS_SIMPLE_GITHUB_TOKEN' ) && WHMCS_SIMPLE_GITHUB_TOKEN;
	$cache_ver       = whmcs_simple_cache_version();
	?>
	<div class="wrap">
		<h1>WHMCS Price Simple</h1>

		<?php if ( $notice_text ) : ?>
			<div class="notice notice-<?php echo esc_attr( $notice_type ); ?> is-dismissible">
				<p><?php echo esc_html( $notice_text ); ?></p>
			</div>
		<?php endif; ?>

		<form method="post">
			<?php wp_nonce_field( 'whmcs_simple_settings' ); ?>
			<table class="form-table">
				<tr>
					<th><label for="whmcs_url"><?php esc_html_e( 'WHMCS Base URL', 'whmcs-price-simple' ); ?></label></th>
					<td>
						<input type="url" id="whmcs_url" name="whmcs_url"
							value="<?php echo esc_attr( $saved_url ); ?>"
							class="regular-text"
							placeholder="https://billing.yourdomain.com" />
						<p class="description">
							<?php esc_html_e( 'Without trailing slash. Example:', 'whmcs-price-simple' ); ?>
							<code>https://billing.yourdomain.com</code>
						</p>
					</td>
				</tr>
				<tr>
					<th><label for="whmcs_ttl"><?php esc_html_e( 'Cache (seconds)', 'whmcs-price-simple' ); ?></label></th>
					<td>
						<input type="number" id="whmcs_ttl" name="whmcs_ttl"
							value="<?php echo esc_attr( $saved_ttl ); ?>"
							class="small-text" min="60" />
						<p class="description"><?php esc_html_e( 'Cache lifetime. Minimum 60s. Default: 3600 (1 hour).', 'whmcs-price-simple' ); ?></p>
					</td>
				</tr>
				<tr>
					<th><label for="whmcs_github_token"><?php esc_html_e( 'GitHub Token', 'whmcs-price-simple' ); ?></label></th>
					<td>
						<?php if ( $token_constant ) : ?>
							<p class="description">
								<strong>✓ <?php esc_html_e( 'Token defined via constant in wp-config.php. The field below is ignored.', 'whmcs-price-simple' ); ?></strong>
							</p>
						<?php else : ?>
							<input type="password" id="whmcs_github_token" name="whmcs_github_token"
								value="<?php echo esc_attr( $saved_token ); ?>"
								class="regular-text" autocomplete="off" />
						<?php endif; ?>
						<p class="description">
							<?php esc_html_e( 'GitHub Personal Access Token to avoid API rate limits (403).', 'whmcs-price-simple' ); ?><br>
							<?php
							printf(
								/* translators: 1: opening <strong>, 2: closing </strong> */
								esc_html__( 'Recommended: define %1$sWHMCS_SIMPLE_GITHUB_TOKEN%2$s in wp-config.php to keep the token out of the database.', 'whmcs-price-simple' ),
								'<strong><code>',
								'</code></strong>'
							);
							?>
							<br>
							<code>define( 'WHMCS_SIMPLE_GITHUB_TOKEN', 'ghp_yourtoken' );</code><br>
							<?php esc_html_e( 'Minimum permissions: Contents read-only on the whmcs-price-simple repository.', 'whmcs-price-simple' ); ?>
						</p>
					</td>
				</tr>
			</table>
			<p class="submit">
				<input type="submit" name="whmcs_simple_save" value="<?php esc_attr_e( 'Save changes', 'whmcs-price-simple' ); ?>" class="button button-primary" />
			</p>
		</form>

		<hr>
		<h2><?php esc_html_e( 'Cache', 'whmcs-price-simple' ); ?></h2>
		<p>
			<?php
			printf(
				/* translators: %d: current cache version number */
				esc_html__( 'Current version: %d', 'whmcs-price-simple' ),
				esc_html( $cache_ver )
			);
			?><br>
			<span class="description"><?php esc_html_e( 'Clearing the cache will force WordPress to fetch fresh prices from WHMCS on the next page visit.', 'whmcs-price-simple' ); ?></span>
		</p>
		<form method="post">
			<?php wp_nonce_field( 'whmcs_simple_purge' ); ?>
			<input type="submit" name="whmcs_simple_purge_cache" value="<?php esc_attr_e( 'Clear cache', 'whmcs-price-simple' ); ?>" class="button button-secondary" />
		</form>

		<hr>
		<h2><?php esc_html_e( 'Shortcode usage', 'whmcs-price-simple' ); ?></h2>
		<p><code>[whmcs pid="10" bc="1m" currency="1"]</code></p>
		<table class="widefat" style="max-width:600px">
			<thead>
				<tr>
					<th><?php esc_html_e( 'Attribute', 'whmcs-price-simple' ); ?></th>
					<th><?php esc_html_e( 'Description', 'whmcs-price-simple' ); ?></th>
					<th><?php esc_html_e( 'Default', 'whmcs-price-simple' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<tr><td><code>pid</code></td><td><?php esc_html_e( 'WHMCS Product ID', 'whmcs-price-simple' ); ?></td><td>—</td></tr>
				<tr><td><code>bc</code></td><td><?php esc_html_e( 'Billing cycle: 1m, 3m, 6m, 1y, 2y, 3y', 'whmcs-price-simple' ); ?></td><td><code>1m</code></td></tr>
				<tr><td><code>currency</code></td><td><?php esc_html_e( 'WHMCS Currency ID (1 = ARS, other = USD)', 'whmcs-price-simple' ); ?></td><td><code>1</code></td></tr>
			</tbody>
		</table>
		<p>
			<?php esc_html_e( 'Output currency=1:', 'whmcs-price-simple' ); ?> <strong>$AR [<?php esc_html_e( 'price', 'whmcs-price-simple' ); ?>] <small>/ <?php esc_html_e( 'month', 'whmcs-price-simple' ); ?></small></strong><br>
			<?php esc_html_e( 'Output currency≠1:', 'whmcs-price-simple' ); ?> <strong>U$S [<?php esc_html_e( 'price', 'whmcs-price-simple' ); ?>] <small>/ monthly</small></strong>
		</p>
	</div>
	<?php
}

// ── Shortcode ─────────────────────────────────────────────────────────────────

add_action( 'init', function () {
	add_shortcode( 'whmcs', 'whmcs_simple_shortcode' );
} );

function whmcs_simple_shortcode( $atts ): string {
	if ( ( defined( 'REST_REQUEST' ) && REST_REQUEST ) || ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) ) {
		return '';
	}

	$atts = shortcode_atts(
		[
			'pid'      => '',
			'bc'       => '1m',
			'currency' => '1',
		],
		$atts,
		'whmcs'
	);

	$pid = absint( $atts['pid'] );
	if ( $pid <= 0 ) {
		return '';
	}

	$bc_map = [
		'1m' => 'monthly',
		'3m' => 'quarterly',
		'6m' => 'semiannually',
		'1y' => 'annually',
		'2y' => 'biennially',
		'3y' => 'triennially',
	];

	$bc       = $bc_map[ $atts['bc'] ] ?? 'monthly';
	$currency = absint( $atts['currency'] ) ?: 1;

	$raw = whmcs_simple_get_price( $pid, $bc, $currency );
	if ( '' === $raw || 'NA' === $raw ) {
		return '';
	}

	// WHMCS may return the price with its own currency symbol (e.g. "$ 500.00").
	// Extract only the numeric value to avoid duplicating the currency prefix.
	$amount = whmcs_simple_extract_amount( $raw );

	if ( 1 === $currency ) {
		/* translators: price suffix for ARS currency, inside <small> tag */
		return '$AR ' . esc_html( $amount ) . ' <small>/ ' . esc_html__( 'mes', 'whmcs-price-simple' ) . '</small>';
	}

	return 'U$S ' . esc_html( $amount ) . ' <small>/ monthly</small>';
}

/**
 * Extracts the numeric value from a WHMCS price string.
 * Handles: "$ 500.00", "ARS 1.500,00", "$500.00", "1,500.00 USD", etc.
 */
function whmcs_simple_extract_amount( string $price ): string {
	$price = trim( $price );
	if ( preg_match( '/\d[\d\s.,]*/', $price, $matches ) ) {
		return rtrim( trim( $matches[0] ), '.,' );
	}
	return $price;
}

// ── Fetch price from WHMCS ────────────────────────────────────────────────────

function whmcs_simple_get_price( int $pid, string $billing_cycle, int $currency ): string {
	global $whmcs_simple_request_cache;

	$whmcs_url = get_option( 'whmcs_simple_url', '' );
	if ( empty( $whmcs_url ) ) {
		return 'NA';
	}

	$cache_key = 'whmcs_s_' . md5( $pid . '_' . $billing_cycle . '_' . $currency );

	// Layer 1: request cache (in-memory).
	if ( isset( $whmcs_simple_request_cache[ $cache_key ] ) ) {
		return $whmcs_simple_request_cache[ $cache_key ];
	}

	// Layer 2: object cache (Redis/Memcached) + transient (DB).
	$cached = whmcs_simple_get_cache( $cache_key );
	if ( false !== $cached ) {
		$whmcs_simple_request_cache[ $cache_key ] = (string) $cached;
		return (string) $cached;
	}

	// Layer 3: HTTP call to WHMCS.
	$url = add_query_arg(
		[
			'pid'          => $pid,
			'get'          => 'price',
			'billingcycle' => $billing_cycle,
			'currency'     => $currency,
		],
		trailingslashit( $whmcs_url ) . 'feeds/productsinfo.php'
	);

	$response = wp_remote_get(
		$url,
		[
			'timeout'    => 15,
			'user-agent' => 'WordPress whmcs-price-simple/' . WHMCS_SIMPLE_VERSION,
			'sslverify'  => true,
		]
	);

	if ( is_wp_error( $response ) || 200 !== (int) wp_remote_retrieve_response_code( $response ) ) {
		return 'NA';
	}

	$body = trim( wp_remote_retrieve_body( $response ) );
	if ( '' === $body ) {
		return 'NA';
	}

	// Unwrap WHMCS JS responses: document.write('500.00');
	if ( preg_match( "/^document\\.write\\('(.*?)'\\);$/s", $body, $matches ) ) {
		$body = $matches[1];
	}

	$body = trim( wp_kses_no_null( $body ) );
	if ( '' === $body ) {
		return 'NA';
	}

	$ttl = (int) get_option( 'whmcs_simple_ttl', 3600 );
	whmcs_simple_set_cache( $cache_key, $body, $ttl );
	$whmcs_simple_request_cache[ $cache_key ] = $body;

	return $body;
}

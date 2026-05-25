<?php
/*
 * Plugin Name: WHMCS Price Simple
 * Description: Muestra precios de productos WHMCS mediante el shortcode [whmcs pid="10" bc="1m" currency="1"].
 * Version:     1.0.1
 * Requires at least: 5.0
 * Requires PHP:      7.4
 * Author:      Fernando Sandmann
 * License:     GPLv2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 *
 * Actualizaciones automáticas desde GitHub vía Plugin Update Checker.
 */

defined( 'ABSPATH' ) || exit;

define( 'WHMCS_SIMPLE_VERSION', '1.0.1' );

// ── Actualizaciones automáticas desde GitHub ─────────────────────────────────
require_once plugin_dir_path( __FILE__ ) . 'vendor/plugin-update-checker/load-v5p6.php';
use YahnisElsts\PluginUpdateChecker\v5\PucFactory;

$whmcsSimpleUpdater = PucFactory::buildUpdateChecker(
	'https://github.com/sis-argentina/whmcs-price-simple/',
	__FILE__,
	'whmcs-price-simple'
);
$whmcsSimpleUpdater->setBranch( 'main' );

// ── Link "Configuración" en la página de plugins ─────────────────────────────
add_filter( 'plugin_action_links_' . plugin_basename( __FILE__ ), function ( $links ) {
	$url  = admin_url( 'options-general.php?page=whmcs_simple' );
	array_unshift( $links, '<a href="' . esc_url( $url ) . '">Configuración</a>' );
	return $links;
} );

// ── Menú de ajustes ─────────────────────────────────────────────────────────

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

	if ( isset( $_POST['whmcs_simple_save'] ) && check_admin_referer( 'whmcs_simple_settings' ) ) {
		$url     = esc_url_raw( trim( sanitize_text_field( wp_unslash( $_POST['whmcs_url'] ?? '' ) ) ) );
		$ttl     = absint( $_POST['whmcs_ttl'] ?? 3600 );
		$ttl     = ( $ttl < 60 ) ? 60 : $ttl;
		update_option( 'whmcs_simple_url', $url );
		update_option( 'whmcs_simple_ttl', $ttl );
		echo '<div class="notice notice-success"><p>Configuración guardada.</p></div>';
	}

	$saved_url = get_option( 'whmcs_simple_url', '' );
	$saved_ttl = (int) get_option( 'whmcs_simple_ttl', 3600 );
	?>
	<div class="wrap">
		<h1>WHMCS Price Simple</h1>
		<form method="post">
			<?php wp_nonce_field( 'whmcs_simple_settings' ); ?>
			<table class="form-table">
				<tr>
					<th><label for="whmcs_url">URL base de WHMCS</label></th>
					<td>
						<input type="url" id="whmcs_url" name="whmcs_url"
							value="<?php echo esc_attr( $saved_url ); ?>"
							class="regular-text"
							placeholder="https://billing.tudominio.com" />
						<p class="description">Sin barra final. Ejemplo: <code>https://billing.tudominio.com</code></p>
					</td>
				</tr>
				<tr>
					<th><label for="whmcs_ttl">Caché (segundos)</label></th>
					<td>
						<input type="number" id="whmcs_ttl" name="whmcs_ttl"
							value="<?php echo esc_attr( $saved_ttl ); ?>"
							class="small-text" min="60" />
						<p class="description">Tiempo de caché de precios. Mínimo 60 s. Default: 3600 (1 hora).</p>
					</td>
				</tr>
			</table>
			<p class="submit">
				<input type="submit" name="whmcs_simple_save" value="Guardar cambios" class="button button-primary" />
			</p>
		</form>

		<hr>
		<h2>Uso del shortcode</h2>
		<p><code>[whmcs pid="10" bc="1m" currency="1"]</code></p>
		<table class="widefat" style="max-width:600px">
			<thead><tr><th>Atributo</th><th>Descripción</th><th>Default</th></tr></thead>
			<tbody>
				<tr><td><code>pid</code></td><td>ID del producto en WHMCS</td><td>—</td></tr>
				<tr><td><code>bc</code></td><td>Ciclo de facturación: <code>1m</code>, <code>3m</code>, <code>6m</code>, <code>1y</code>, <code>2y</code>, <code>3y</code></td><td><code>1m</code></td></tr>
				<tr><td><code>currency</code></td><td>ID de moneda en WHMCS (1 = ARS, otro = USD)</td><td><code>1</code></td></tr>
			</tbody>
		</table>
		<p>
			Salida currency=1: <strong>$AR [precio] <small>/ mes</small></strong><br>
			Salida currency≠1: <strong>U$S [precio] <small>/ monthly</small></strong>
		</p>
	</div>
	<?php
}

// ── Shortcode ────────────────────────────────────────────────────────────────

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

	$price = whmcs_simple_get_price( $pid, $bc, $currency );
	if ( '' === $price || 'NA' === $price ) {
		return '';
	}

	if ( 1 === $currency ) {
		return '$AR ' . esc_html( $price ) . ' <small>/ mes</small>';
	}

	return 'U$S ' . esc_html( $price ) . ' <small>/ monthly</small>';
}

// ── Obtención del precio desde WHMCS ────────────────────────────────────────

function whmcs_simple_get_price( int $pid, string $billing_cycle, int $currency ): string {
	$whmcs_url = get_option( 'whmcs_simple_url', '' );
	if ( empty( $whmcs_url ) ) {
		return 'NA';
	}

	$cache_key = 'whmcs_s_' . md5( $pid . '_' . $billing_cycle . '_' . $currency );
	$cached    = get_transient( $cache_key );
	if ( false !== $cached ) {
		return (string) $cached;
	}

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

	if ( is_wp_error( $response ) ) {
		return 'NA';
	}

	if ( 200 !== (int) wp_remote_retrieve_response_code( $response ) ) {
		return 'NA';
	}

	$body = trim( wp_remote_retrieve_body( $response ) );
	if ( '' === $body ) {
		return 'NA';
	}

	// Desenvuelve respuestas JS de WHMCS: document.write('500.00');
	if ( preg_match( "/^document\\.write\\('(.*?)'\\);$/s", $body, $matches ) ) {
		$body = $matches[1];
	}

	$body = trim( wp_kses_no_null( $body ) );
	if ( '' === $body ) {
		return 'NA';
	}

	$ttl = (int) get_option( 'whmcs_simple_ttl', 3600 );
	set_transient( $cache_key, $body, $ttl );

	return $body;
}

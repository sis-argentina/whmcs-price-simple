<?php
/*
 * Plugin Name: WHMCS Price Simple
 * Description: Muestra precios de productos WHMCS mediante el shortcode [whmcs pid="10" bc="1m" currency="1"].
 * Version:     1.0.3
 * Requires at least: 5.0
 * Requires PHP:      7.4
 * Author:      Fernando Sandmann
 * License:     GPLv2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 *
 * Actualizaciones automáticas desde GitHub vía Plugin Update Checker.
 */

defined( 'ABSPATH' ) || exit;

define( 'WHMCS_SIMPLE_VERSION', '1.0.3' );

// ── Actualizaciones automáticas desde GitHub ─────────────────────────────────
require_once plugin_dir_path( __FILE__ ) . 'vendor/plugin-update-checker/load-v5p6.php';
use YahnisElsts\PluginUpdateChecker\v5\PucFactory;

$whmcsSimpleUpdater = PucFactory::buildUpdateChecker(
	'https://github.com/sis-argentina/whmcs-price-simple/',
	__FILE__,
	'whmcs-price-simple'
);
// Sin setBranch: PUC usa la última GitHub Release para detectar actualizaciones.
// Flujo: push del código → crear release en GitHub con tag v1.x.x → WordPress detecta el update.

// ── Link "Configuración" en la página de plugins ─────────────────────────────
add_filter( 'plugin_action_links_' . plugin_basename( __FILE__ ), function ( $links ) {
	$url = admin_url( 'options-general.php?page=whmcs_simple' );
	array_unshift( $links, '<a href="' . esc_url( $url ) . '">Configuración</a>' );
	return $links;
} );

// ── Caché en tres capas ───────────────────────────────────────────────────────
// 1. Request cache  — array en memoria, vive un solo PHP request.
// 2. Object cache   — Redis/Memcached via wp_cache_get/set (si está disponible).
// 3. Transients     — base de datos, fallback universal.
//
// La invalidación usa versioned keys: incrementar whmcs_simple_cache_version
// hace que todas las claves previas sean inaccesibles sin necesidad de un
// inventario de keys (funciona igual con object cache persistente).

/** @var array<string,string> Request-scoped in-memory cache. */
$whmcs_simple_request_cache = [];

/** @var int|null Cache version, lazy-loaded. */
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

// ── Menú de ajustes ──────────────────────────────────────────────────────────

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
		$notice_text = sprintf( 'Caché borrado correctamente. Versión actual: %d.', $new_ver );
	}

	if ( isset( $_POST['whmcs_simple_save'] ) && check_admin_referer( 'whmcs_simple_settings' ) ) {
		$url = esc_url_raw( trim( sanitize_text_field( wp_unslash( $_POST['whmcs_url'] ?? '' ) ) ) );
		$ttl = absint( $_POST['whmcs_ttl'] ?? 3600 );
		$ttl = max( 60, $ttl );
		update_option( 'whmcs_simple_url', $url );
		update_option( 'whmcs_simple_ttl', $ttl );
		$notice_type = 'success';
		$notice_text = 'Configuración guardada.';
	}

	$saved_url = get_option( 'whmcs_simple_url', '' );
	$saved_ttl = (int) get_option( 'whmcs_simple_ttl', 3600 );
	$cache_ver = whmcs_simple_cache_version();
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
						<p class="description">Tiempo de vida del caché. Mínimo 60 s. Default: 3600 (1 hora).</p>
					</td>
				</tr>
			</table>
			<p class="submit">
				<input type="submit" name="whmcs_simple_save" value="Guardar cambios" class="button button-primary" />
			</p>
		</form>

		<hr>
		<h2>Caché</h2>
		<p>
			Versión actual: <strong><?php echo esc_html( $cache_ver ); ?></strong><br>
			<span class="description">Al borrar el caché, WordPress pedirá precios frescos a WHMCS en la próxima visita a cada página.</span>
		</p>
		<form method="post">
			<?php wp_nonce_field( 'whmcs_simple_purge' ); ?>
			<input type="submit" name="whmcs_simple_purge_cache" value="Borrar caché" class="button button-secondary" />
		</form>

		<hr>
		<h2>Uso del shortcode</h2>
		<p><code>[whmcs pid="10" bc="1m" currency="1"]</code></p>
		<table class="widefat" style="max-width:600px">
			<thead><tr><th>Atributo</th><th>Descripción</th><th>Default</th></tr></thead>
			<tbody>
				<tr><td><code>pid</code></td><td>ID del producto en WHMCS</td><td>—</td></tr>
				<tr><td><code>bc</code></td><td>Ciclo: <code>1m</code>, <code>3m</code>, <code>6m</code>, <code>1y</code>, <code>2y</code>, <code>3y</code></td><td><code>1m</code></td></tr>
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

	// WHMCS puede devolver el precio con su propio símbolo (ej: "$ 500.00").
	// Se extrae solo el valor numérico para evitar duplicar el código de moneda.
	$amount = whmcs_simple_extract_amount( $raw );

	if ( 1 === $currency ) {
		return '$AR ' . esc_html( $amount ) . ' <small>/ mes</small>';
	}

	return 'U$S ' . esc_html( $amount ) . ' <small>/ monthly</small>';
}

/**
 * Extrae el valor numérico de un string de precio de WHMCS.
 * Maneja: "$ 500.00", "ARS 1.500,00", "$500.00", "1,500.00 USD", etc.
 */
function whmcs_simple_extract_amount( string $price ): string {
	$price = trim( $price );
	if ( preg_match( '/\d[\d\s.,]*/', $price, $matches ) ) {
		return rtrim( trim( $matches[0] ), '.,' );
	}
	return $price;
}

// ── Obtención del precio desde WHMCS ─────────────────────────────────────────

function whmcs_simple_get_price( int $pid, string $billing_cycle, int $currency ): string {
	global $whmcs_simple_request_cache;

	$whmcs_url = get_option( 'whmcs_simple_url', '' );
	if ( empty( $whmcs_url ) ) {
		return 'NA';
	}

	$cache_key = 'whmcs_s_' . md5( $pid . '_' . $billing_cycle . '_' . $currency );

	// Capa 1: request cache (in-memory).
	if ( isset( $whmcs_simple_request_cache[ $cache_key ] ) ) {
		return $whmcs_simple_request_cache[ $cache_key ];
	}

	// Capa 2: object cache (Redis/Memcached) + transient (DB).
	$cached = whmcs_simple_get_cache( $cache_key );
	if ( false !== $cached ) {
		$whmcs_simple_request_cache[ $cache_key ] = (string) $cached;
		return (string) $cached;
	}

	// Capa 3: llamada HTTP a WHMCS.
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

	// Desenvuelve respuestas JS de WHMCS: document.write('500.00');
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

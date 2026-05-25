=== WHMCS Price Simple ===
Contributors: sis-argentina
Requires at least: 5.0
Tested up to: 6.7
Stable tag: 1.0.4
Requires PHP: 7.4
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Muestra precios de productos WHMCS mediante un shortcode.

== Description ==

Plugin standalone para mostrar precios de productos WHMCS en cualquier página o entrada de WordPress mediante el shortcode `[whmcs]`.

No depende de wordpress.org. Las actualizaciones se distribuyen directamente desde GitHub.

**Uso básico:**

`[whmcs pid="10" bc="1m" currency="1"]`

**Parámetros:**

* `pid` — ID del producto en WHMCS
* `bc` — Ciclo de facturación: `1m`, `3m`, `6m`, `1y`, `2y`, `3y` (default: `1m`)
* `currency` — ID de moneda en WHMCS (default: `1`)

**Salida:**

* `currency="1"` → `$AR 500.00 / mes`
* `currency="2"` → `U$S 500.00 / monthly`

== Installation ==

1. Descargá el ZIP desde el repositorio de GitHub.
2. En WordPress: Plugins → Añadir nuevo → Subir plugin.
3. Activá el plugin.
4. Configurá la URL de tu WHMCS en Ajustes → WHMCS Price Simple.

== Changelog ==

= 1.0.4 =
* Agrega campo GitHub Token en ajustes para autenticar llamadas a la API de GitHub y evitar error 403.

= 1.0.3 =
* Cambia detección de actualizaciones a GitHub Releases (más confiable que branch tracking).

= 1.0.2 =
* Caché en tres capas: request cache (in-memory), object cache (Redis/Memcached) y transients (DB).
* Versioned keys: el botón "Borrar caché" invalida todas las entradas atómicamente sin importar el backend.
* Nuevo botón "Borrar caché" en la página de ajustes.
* Corrige precio duplicado: se extrae solo el valor numérico de la respuesta de WHMCS antes de añadir el prefijo $AR / U$S.

= 1.0.1 =
* Agrega link "Configuración" en la página de plugins de WordPress.

= 1.0.0 =
* Versión inicial.
* Shortcode `[whmcs]` con soporte para `pid`, `bc` y `currency`.
* Caché via transients de WordPress configurable.
* Actualizaciones automáticas desde GitHub via Plugin Update Checker.

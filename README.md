# WHMCS Price Simple

Plugin de WordPress para mostrar precios de productos WHMCS mediante un shortcode. Standalone, sin dependencias de wordpress.org. Las actualizaciones se aplican directamente desde este repositorio.

## Instalación

1. Descargá el ZIP desde **Code → Download ZIP**
2. En WordPress: **Plugins → Añadir nuevo → Subir plugin**
3. Activá el plugin
4. Configurá la URL de tu WHMCS en **Ajustes → WHMCS Price Simple**

## Uso

```
[whmcs pid="10" bc="1m" currency="1"]
```

| Atributo | Descripción | Default |
|---|---|---|
| `pid` | ID del producto en WHMCS | — |
| `bc` | Ciclo de facturación | `1m` |
| `currency` | ID de moneda en WHMCS | `1` |

**Ciclos de facturación (`bc`):**

| Valor | Ciclo |
|---|---|
| `1m` | Mensual |
| `3m` | Trimestral |
| `6m` | Semestral |
| `1y` | Anual |
| `2y` | Bienal |
| `3y` | Trienal |

**Salida según moneda:**

- `currency="1"` → `$AR 500.00 / mes`
- `currency="2"` (o cualquier otro) → `U$S 500.00 / monthly`

El `/mes` y `/monthly` se renderizan dentro de `<small>`.

## Actualizaciones

El plugin usa [Plugin Update Checker](https://github.com/YahnisElsts/plugin-update-checker) con **GitHub Releases**. WordPress detecta la actualización cuando existe una release con un tag de versión mayor a la instalada.

Para publicar una nueva versión:

1. Actualizá `Version:` en el header de `whmcs-price.php`
2. Actualizá `WHMCS_SIMPLE_VERSION` en el código
3. Actualizá `Stable tag:` en `readme.txt` y agregá entrada al changelog
4. Commit y push a `main`
5. Creá una **GitHub Release** con tag `v1.x.x` desde la página del repositorio

WordPress chequea automáticamente al entrar a la página de Plugins (máximo una vez cada 5 minutos).

## Changelog

### 1.0.8
- Corrige carga de traducciones usando `load_textdomain()` directo en lugar del filtro `plugin_locale`, que es ignorado por el sistema JIT de WordPress 6.1+.
- La autodetección de español (`es_ES`, `es_AR`, etc.) ahora funciona correctamente.

### 1.0.7
- Corrige detección automática de español: cualquier variante `es_*` (`es_AR`, `es_ES`, `es_MX`…) carga el `.mo` correcto en modo Auto.

### 1.0.6
- Selector de idioma en ajustes: Automático / Español / English, independiente del idioma de WordPress.
- Chequeo automático de actualizaciones al entrar a la página de plugins (throttle de 5 minutos).

### 1.0.5
- Soporte completo de traducciones (i18n). Español automático para todas las variantes `es_*`, inglés para el resto.
- GitHub token: se puede definir con la constante `WHMCS_SIMPLE_GITHUB_TOKEN` en `wp-config.php` (recomendado) o desde el panel de ajustes.
- Página de ajustes muestra aviso cuando el token está definido por constante.

### 1.0.4
- Agrega campo GitHub Token en ajustes para autenticar llamadas a la API de GitHub y evitar error 403.

### 1.0.3
- Cambia detección de actualizaciones a GitHub Releases (más confiable que branch tracking).

### 1.0.2
- Caché en tres capas: request cache (in-memory), object cache (Redis/Memcached) y transients (DB).
- Versioned keys: el botón "Borrar caché" invalida todas las entradas atómicamente sin importar el backend.
- Nuevo botón "Borrar caché" en la página de ajustes.
- Corrige precio duplicado: se extrae solo el valor numérico de la respuesta de WHMCS antes de añadir el prefijo `$AR` / `U$S`.

### 1.0.1
- Agrega link "Configuración" en la página de plugins de WordPress.

### 1.0.0
- Versión inicial.
- Shortcode `[whmcs]` con soporte para `pid`, `bc` y `currency`.
- Caché via transients de WordPress configurable.
- Actualizaciones automáticas desde GitHub via Plugin Update Checker.

## Licencia

GPLv2 or later.

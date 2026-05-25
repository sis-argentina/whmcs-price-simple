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
5. Creá una **GitHub Release** con tag `v1.x.x` (ej: `v1.0.3`) desde la página del repositorio

WordPress detecta el update en el próximo chequeo automático (cada 12 h) o al visitar **Dashboard → Actualizaciones**.

## Licencia

GPLv2 or later.

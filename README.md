# hostingmexico-contents

Sitio de venta de hosting de hostingmexico.org. Claude escribe aquí y GitHub Actions lo aplica en WordPress por la API REST.

**Venta automática:** cliente paga en WooCommerce (Mercado Pago) → el plugin `hm-whm` crea la cuenta cPanel en WHM → el cliente recibe sus accesos por correo → el plugin avisa, suspende y reactiva según las renovaciones.

## Estructura

```
content/paginas/*.md                  Páginas del sitio (inicio, FAQ, contacto, legales)
docs/sitio.yml                        Configuración: plugins, WooCommerce, producto, menú, lanzamiento
scripts/publish.py                    Publica Markdown o bloques en WordPress
scripts/site_setup.py                 Aplica docs/sitio.yml
.github/workflows/publish.yml         Corre todo en cada push; botón manual (check, all, setup)
wordpress/plugins/hm-whm/             Plugin de aprovisionamiento WHM (se sube a mano una vez)
wordpress/mu-plugins/agente-auth.php  Rescata la credencial si el servidor borra Authorization
registro/ultima-publicacion.log       Log de la última ejecución
```

## Plan que se vende

| | Hosting Básico |
|---|---|
| Precio | $1,899 MXN al año, IVA incluido (mismo precio al renovar) |
| Sitio | 1 dominio principal, 5 subdominios |
| Disco / transferencia | 20 GB NVMe / 250 GB al mes |
| Correo / bases de datos | 10 cuentas / 5 MySQL |
| Paquete WHM | `hm_basico` (el plugin lo crea si no existe) |

Para cambiar especificaciones hay que tocar tres lugares: el texto (`content/paginas/`), el producto (`docs/sitio.yml`) y el paquete (filtro `hm_whm_package_specs` o directamente en WHM).

## Puesta en marcha

1. **Push de este repo**: instala WooCommerce y Mercado Pago, crea el producto en borrador, publica las páginas en borrador y manda el contenido de ejemplo a la papelera.
2. **Plugin WHM**: en WP Admin > Plugins > Añadir nuevo > Subir plugin, sube `hm-whm.zip` (es la carpeta `wordpress/plugins/hm-whm/` comprimida) y actívalo.
3. **Token de WHM**: en WHM > Development > Manage API Tokens crea un token (permisos: crear cuentas, suspender, listar y crear paquetes, crear sesiones de usuario). Pégalo en WooCommerce > Hosting WHM junto con el servidor, o defínelo en `wp-config.php`:
   ```php
   define( 'HM_WHM_HOST', 'servidor.tudominio.com' );
   define( 'HM_WHM_USER', 'root' );
   define( 'HM_WHM_TOKEN', '...' );
   ```
   Luego usa **Probar conexión** y **Preparar paquete en WHM**.
4. **Mercado Pago**: en WooCommerce > Ajustes > Pagos > Mercado Pago, conecta tu cuenta (credenciales de producción).
5. **Lanzar**: cambia `publicar: true` en `docs/sitio.yml` y `status: publish` en las páginas. Eso publica el producto, fija la portada, crea menú, encabezado y pie, y quita el modo "Próximamente".

## Cómo funciona el plugin hm-whm

- **Checkout**: pide el dominio (valida formato y que no tenga ya un hosting activo).
- **Pago confirmado**: crea la cuenta en WHM con un usuario derivado del dominio y una contraseña segura, completa el pedido y envía los accesos y DNS.
- **Mi cuenta > Mis hostings**: estado, vencimiento, botón para entrar a cPanel sin contraseña y botón para renovar.
- **Revisión diaria (9:00)**: avisa 15, 3 y 0 días antes de vencer con un enlace de pago; suspende tras 7 días de gracia; reactiva al pagar; alerta al admin a los 30 días de suspensión. **Nunca elimina cuentas.**
- **Errores**: si WHM falla, el pedido queda pagado con una nota y te llega un correo; corrígelo y usa *Acciones del pedido > Crear cuenta de hosting (reintentar)*.
- **Diagnóstico**: `GET /wp-json/hm-whm/v1/estado` (el modo setup del workflow lo imprime en el registro).

## Frontmatter de contenidos

```yaml
---
title: "Título"             # obligatorio
slug: titulo                 # obligatorio
type: page                   # post | page
status: draft                # draft | publish | pending | private
format: blocks               # opcional: cuerpo en bloques de Gutenberg en vez de Markdown
template: page-no-title      # opcional: plantilla del tema
excerpt: "Resumen"
---
```

En el cuerpo, `{{comprar}}` se reemplaza por la página de pago con el Hosting Básico ya en el carrito.

## Errores típicos

- `401 rest_not_logged_in`: el header se pierde; sube `wordpress/mu-plugins/agente-auth.php`.
- `403`: firewall o plugin de seguridad bloqueando `/wp-json/`.
- `plugins_api_failed` al instalar plugins: el servidor no puede conectar con WordPress.org o tiene `DISALLOW_FILE_MODS`.
- `WHM rechazó el token`: revisa usuario, token y que la IP del servidor de WordPress esté permitida en WHM.

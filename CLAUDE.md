# Instrucciones para Claude

Este repo alimenta hostingmexico.org, un sitio que vende un solo plan de hosting (Hosting Básico, $1,899 MXN al año) con WooCommerce, Mercado Pago y aprovisionamiento automático en WHM (plugin `wordpress/plugins/hm-whm`). Cada push a `main` se aplica vía `.github/workflows/publish.yml` con el usuario `soporte`.

- Páginas: `content/paginas/<slug>.md`. Entradas de blog: `content/blog/<slug>.md`. Formato en README.md.
- Configuración del sitio y del producto: `docs/sitio.yml` (se aplica con `scripts/site_setup.py`).
- Usar `status: draft` salvo que el usuario pida publicar. `publicar: true` en sitio.yml lanza la tienda.
- Precio, especificaciones y políticas (7 días de gracia, 30 días de garantía, avisos 15/3/0 días) deben coincidir entre páginas, producto y plugin. Si cambias uno, cambia todos.
- Idioma: español de México. No usar guión largo (—) en los textos.
- Después de cada push, `git pull` y leer `registro/ultima-publicacion.log` para confirmar el resultado.
- El plugin hm-whm y los mu-plugins (agente-auth, hostingmexico-gtm) no se despliegan por el workflow: si cambian, el usuario debe volver a subirlos.
- Nunca pedir ni guardar tokens o contraseñas en el repo: van en los secretos de GitHub o en wp-config.php.
- El repo es público: nunca guardar ni imprimir en registros datos de clientes (dominios, usuarios de cPanel, correos, IP, uso de cuentas). Solo totales; el detalle va a WP Admin o al correo del administrador.

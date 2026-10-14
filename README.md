# hostingmexico-contents

Contenidos de hostingmexico.org. Claude escribe Markdown aquí y GitHub Actions lo publica en WordPress por la API REST.

Flujo: **Claude → push a `main` → workflow “Publicar en WordPress” → API REST (usuario `soporte`, contraseña de aplicación)**

## Estructura

```
content/<sección>/*.md                Entradas y páginas (Markdown con frontmatter)
content/pruebas/prueba-conexion.md    Entrada de prueba (borrador)
scripts/publish.py                    Publica Markdown en WordPress; crea categorías y etiquetas si faltan
.github/workflows/publish.yml         Publica lo que cambie en content/ en cada push; botón manual en modo check
wordpress/mu-plugins/agente-auth.php  Rescata la credencial si el servidor borra el header Authorization
registro/ultima-publicacion.log       Log de la última ejecución (lo escribe el workflow)
images/                               Imágenes destacadas
```

## Configuración (una sola vez)

1. **WordPress**: usuario `soporte` con rol Editor. En su perfil, crea una contraseña de aplicación (ej. “agente-claude”).
2. **mu-plugin**: sube `wordpress/mu-plugins/agente-auth.php` a `wp-content/mu-plugins/`.
3. **Secretos** en Settings → Secrets and variables → Actions:
   - `WP_URL` = `https://www.hostingmexico.org`
   - `WP_USER` = `soporte`
   - `WP_APP_PASSWORD` = la contraseña de aplicación
4. **Probar**: Actions → Publicar en WordPress → Run workflow → modo `check`.

## Errores típicos

- `401 rest_not_logged_in`: el header se pierde; revisa que el mu-plugin esté subido.
- `403`: firewall o plugin de seguridad (Wordfence, Cloudflare) bloqueando `/wp-json/`.
- `FALTA` en el diagnóstico de secretos: el secreto no está creado o tiene otro nombre.

## Frontmatter

```yaml
---
title: "Título"             # obligatorio
slug: titulo                 # obligatorio
type: post                   # post | page
status: draft                # draft | publish | pending | private
date: 2026-10-20 09:00       # hora de Ciudad de México; si es futura y status publish, se programa
excerpt: "Resumen"
categories: [hosting]        # slugs, se crean si no existen
tags: [WordPress, dominios]  # nombres, se crean si no existen
parent: slug-padre           # solo páginas
featured_image: images/archivo.png
featured_alt: "Texto alternativo"
id: 123                      # opcional: actualiza esa entrada aunque cambie el slug
---
```

Si ya existe una entrada con el mismo slug, se actualiza en vez de duplicarse. Los enlaces externos llevan `rel="nofollow"` y los comentarios quedan cerrados.

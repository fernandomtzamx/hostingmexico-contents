# hostingmexico-contents

Contenidos de hostingmexico.org gestionados por Claude a través de GitHub.

Flujo: **Claude → commit en este repo → webhook de GitHub → mu-plugin `agente-auth` → WordPress (usuario `soporte`)**

## Estructura

```
mu-plugins/agente-auth.php   Plugin que conecta WordPress con este repo
content/posts/*.md           Entradas (post)
content/pages/*.md           Páginas (page)
wp-config.example.php        Constantes de configuración
```

## Instalación en WordPress

1. **Usuario**: en wp-admin crea (o confirma) el usuario `soporte` con rol **Editor** (o Administrador si también gestionará ajustes).
2. **Plugin**: sube `mu-plugins/agente-auth.php` a `wp-content/mu-plugins/agente-auth.php` (crea la carpeta si no existe). Los mu-plugins se activan solos.
3. **Configuración**: copia las constantes de `wp-config.example.php` a tu `wp-config.php` y genera valores propios con `openssl rand -hex 32`.
4. **Webhook de GitHub**: en este repo, *Settings → Webhooks → Add webhook*:
   - Payload URL: `https://hostingmexico.org/wp-json/agente/v1/webhook`
   - Content type: `application/json`
   - Secret: el mismo valor de `AGENTE_GITHUB_SECRET`
   - Eventos: *Just the push event*
5. **Prueba**:
   ```bash
   curl -H "Authorization: Bearer TU_AGENTE_AUTH_TOKEN" https://hostingmexico.org/wp-json/agente/v1/ping
   ```
   Debe responder `{"ok":true,"user":"soporte",...}`.

> Si el servidor (Apache/CGI) elimina la cabecera `Authorization`, agrega a `.htaccess`:
> `SetEnvIf Authorization "(.*)" HTTP_AUTHORIZATION=$1`
> o usa la cabecera alternativa `X-Agente-Token: TU_TOKEN`.

## Endpoints

| Método | Ruta | Auth | Uso |
|---|---|---|---|
| GET | `/wp-json/agente/v1/ping` | Bearer | Verifica conexión y usuario |
| POST | `/wp-json/agente/v1/sync` | Bearer | Sincroniza todo el repo, o un archivo con `{"path":"content/posts/x.md"}` |
| POST | `/wp-json/agente/v1/webhook` | Firma HMAC de GitHub | Sincroniza automáticamente los archivos del push |

Además, con el Bearer token Claude puede usar toda la REST API estándar (`/wp-json/wp/v2/posts`, `/media`, etc.) actuando como `soporte`.

## Formato de contenido

```markdown
---
title: "Título del post"
slug: titulo-del-post
status: draft          # draft | publish | pending | private
date: 2026-10-14
excerpt: "Resumen corto"
categories: [Hosting, Guías]
tags: [wordpress, dominios]
featured_image: https://ejemplo.com/imagen.jpg
---

Contenido en **Markdown**. También se acepta HTML.
```

- Los archivos en `content/pages/` se crean como páginas (admiten `parent: slug-padre`).
- Si `status` no se indica, se crea como **borrador**.
- Editar un archivo actualiza el mismo post (se enlaza por la ruta del archivo).
- Borrar un archivo pasa el post a borrador (no lo elimina).

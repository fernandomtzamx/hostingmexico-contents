# Instrucciones para Claude

Este repo alimenta el sitio WordPress hostingmexico.org. Cada push a `main` se publica en el sitio vía el mu-plugin `agente-auth` (usuario `soporte`).

- Entradas nuevas: `content/posts/<slug>.md`. Páginas: `content/pages/<slug>.md`.
- Siempre incluir front matter con `title`, `slug` y `status`. Usar `status: draft` salvo que el usuario pida publicar.
- Idioma: español de México. No usar guión largo (—) en los textos.
- No modificar `mu-plugins/agente-auth.php` salvo que se pida explícitamente; si se modifica, el usuario debe volver a subirlo al servidor.
- Formato y campos disponibles: ver README.md.

# Instrucciones para Claude

Este repo alimenta hostingmexico.org. Cada push a `main` que toque `content/` se publica vía `.github/workflows/publish.yml` con el usuario `soporte`.

- Contenido nuevo: `content/<sección>/<slug>.md` con frontmatter (`title`, `slug`, `status`). Formato completo en README.md.
- Usar `status: draft` salvo que el usuario pida publicar o programar.
- Idioma: español de México. No usar guión largo (—) en los textos.
- Después de cada push, leer `registro/ultima-publicacion.log` (hacer `git pull`, el workflow lo escribe unos segundos después) para confirmar el resultado.
- Si `wordpress/mu-plugins/agente-auth.php` cambia, el usuario debe volver a subirlo al servidor.

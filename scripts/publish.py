#!/usr/bin/env python3
"""Publica archivos Markdown con frontmatter en WordPress vía API REST.

Uso:
    python scripts/publish.py content/glosario/brief-creativo.md [...]
    python scripts/publish.py --all

Variables de entorno requeridas:
    WP_URL           https://www.hostingmexico.org
    WP_USER          usuario de WordPress (soporte)
    WP_APP_PASSWORD  contraseña de aplicación

Frontmatter soportado:
    title:        título (obligatorio)
    slug:         slug de la URL (obligatorio)
    type:         post | page (por defecto post)
    status:       draft | publish | pending | private (por defecto draft)
    excerpt:      extracto / descripción corta
    categories:   lista de slugs de categoría (se crean si no existen)
    tags:         lista de nombres de etiqueta (se crean si no existen)
    parent:       slug de la página padre (solo páginas)
    format:       markdown (por defecto) | blocks (cuerpo en marcado de bloques de Gutenberg)
    template:     plantilla del tema, ej. page-no-title

Marcadores en el cuerpo:
    {{comprar}}   página de pago con el producto hosting-basico ya en el carrito

Si ya existe una entrada o página con el mismo slug, se actualiza en lugar de duplicarse.
"""
import argparse
import base64
import datetime
import os
import pathlib
import re
import sys

import markdown
import requests
import yaml

ROOT = pathlib.Path(__file__).resolve().parent.parent
CONTENT_DIR = ROOT / "content"
VALID_STATUS = {"draft", "publish", "pending", "private", "future"}


def env(name):
    value = os.environ.get(name, "").strip()
    if not value:
        sys.exit(f"Falta la variable de entorno {name}")
    return value


class WP:
    def __init__(self, base, user, password):
        # Resolver redirecciones (http->https, sin www->www) antes de autenticar:
        # requests descarta el encabezado Authorization al cambiar de host.
        probe = requests.get(base.rstrip("/") + "/wp-json/", timeout=30,
                             headers={"User-Agent": "hostingmexico-agente/1.1"})
        final = probe.url.split("/wp-json")[0]
        if final.rstrip("/") != base.rstrip("/"):
            print(f"Aviso: {base} redirige a {final}; uso la URL final.")
        print(f"Endpoint: {final}/wp-json/ (HTTP {probe.status_code})")
        try:
            auth = list((probe.json().get("authentication") or {}).keys())
            print(f"Métodos de autenticación anunciados: {', '.join(auth) or 'ninguno'}")
        except ValueError:
            print("Aviso: /wp-json/ no devolvió JSON")
        self.api = final.rstrip("/") + "/wp-json/wp/v2"
        self.s = requests.Session()
        self.s.auth = (user, password.replace(" ", ""))
        self.s.headers["User-Agent"] = "hostingmexico-agente/1.1"
        # Copia de la credencial en un encabezado propio, por si el servidor borra Authorization
        # (lo recibe el mu-plugin wordpress/mu-plugins/agente-auth.php).
        token = base64.b64encode(f"{user}:{password.replace(' ', '')}".encode()).decode()
        self.s.headers["X-Agente-Auth"] = f"Basic {token}"
        self.s.headers["X-Publimerca-Auth"] = f"Basic {token}"  # compatibilidad
        self._term_cache = {}

    def req(self, method, path, **kw):
        r = self.s.request(method, f"{self.api}/{path}", timeout=30, **kw)
        if r.status_code >= 400:
            raise RuntimeError(f"{method} {path} -> {r.status_code}: {r.text[:300]}")
        return r.json()

    def whoami(self):
        import time
        params = {"context": "edit", "_nc": str(int(time.time() * 1000))}
        r = self.s.get(f"{self.api}/users/me", params=params, timeout=30,
                       headers={"Cache-Control": "no-cache", "Pragma": "no-cache"})
        if r.status_code == 200:
            try:
                return r.json()
            except ValueError:
                sys.exit(f"DIAGNÓSTICO: el servidor respondió 200 sin JSON (posible bloqueo o caché). "
                         f"server={r.headers.get('server')} | inicio: {r.text[:200]!r}")
        print(f"Servidor: {r.headers.get('server', '?')} | via: {r.headers.get('via', '-')} "
              f"| cf-ray: {'sí' if 'cf-ray' in r.headers else 'no'}")
        interesting = {k: v for k, v in r.headers.items()
                       if any(s in k.lower() for s in ("cache", "litespeed", "x-", "allow", "www-auth", "set-cookie"))}
        for k, v in interesting.items():
            print(f"  encabezado {k}: {v[:120]}")
        print(f"Respuesta con credencial real: {r.status_code} {r.json().get('code') if r.headers.get('content-type','').startswith('application/json') else r.text[:120]}")
        fake = base64.b64encode(f"{self.s.auth[0]}:xxxxxxxxxxxxxxxxxxxxxxxx".encode()).decode()
        bogus = requests.get(f"{self.api}/users/me", auth=(self.s.auth[0], "xxxxxxxxxxxxxxxxxxxxxxxx"),
                             headers={**self.s.headers, "X-Agente-Auth": f"Basic {fake}", "X-Publimerca-Auth": f"Basic {fake}"}, timeout=30)
        code = bogus.json().get("code") if bogus.headers.get("content-type", "").startswith("application/json") else bogus.text[:120]
        print(f"Respuesta con contraseña falsa: {bogus.status_code} {code}")
        if code == "rest_not_logged_in":
            sys.exit("DIAGNÓSTICO: el encabezado Authorization NO llega a WordPress (lo borra el servidor o un plugin).")
        sys.exit("DIAGNÓSTICO: el encabezado SÍ llega; la credencial real es la que falla (usuario o contraseña).")

    def find_by_slug(self, endpoint, slug):
        res = self.req("GET", endpoint, params={"slug": slug, "status": "any", "context": "edit"})
        return res[0] if res else None

    def term_id(self, taxonomy, value, by="slug"):
        key = (taxonomy, value)
        if key in self._term_cache:
            return self._term_cache[key]
        params = {"slug": value} if by == "slug" else {"search": value}
        found = [t for t in self.req("GET", taxonomy, params=params)
                 if (t["slug"] == value if by == "slug" else t["name"].lower() == value.lower())]
        if found:
            tid = found[0]["id"]
        else:
            payload = {"name": value.replace("-", " ").capitalize() if by == "slug" else value}
            if by == "slug":
                payload["slug"] = value
            tid = self.req("POST", taxonomy, json=payload)["id"]
            label = {"categories": "categoría", "tags": "etiqueta"}.get(taxonomy, taxonomy)
            print(f"  + {label} creada: {value}")
        self._term_cache[key] = tid
        return tid


def media_id(self, file_path, alt):
    """Sube una imagen una sola vez (la reutiliza por slug) y devuelve su id."""
    file_path = pathlib.Path(file_path)
    if not file_path.exists():
        raise FileNotFoundError(f"imagen no encontrada: {file_path}")
    slug = file_path.stem.lower()
    found = self.req("GET", "media", params={"slug": slug, "context": "edit"})
    if not found:
        # WordPress puede renombrar el slug del adjunto (p. ej. "slug-2"); buscar por archivo.
        found = [m for m in self.req("GET", "media", params={"search": slug, "context": "edit", "per_page": 20})
                 if pathlib.Path(m.get("source_url", "")).stem.startswith(slug)]
        found.sort(key=lambda m: m["id"], reverse=True)
    if found:
        mid = found[0]["id"]
        self.req("POST", f"media/{mid}", json={"alt_text": alt})
        print(f"  = imagen reutilizada: {slug} (id {mid})")
        return mid
    mime = {".png": "image/png", ".jpg": "image/jpeg", ".jpeg": "image/jpeg", ".webp": "image/webp"}[file_path.suffix.lower()]
    r = self.s.post(f"{self.api}/media", data=file_path.read_bytes(), timeout=60,
                    headers={"Content-Type": mime,
                             "Content-Disposition": f'attachment; filename="{file_path.name}"'})
    if r.status_code >= 400:
        raise RuntimeError(f"POST media -> {r.status_code}: {r.text[:300]}")
    mid = r.json()["id"]
    self.req("POST", f"media/{mid}", json={"alt_text": alt, "title": alt})
    print(f"  + imagen subida: {file_path.name} (id {mid})")
    return mid


WP.media_id = media_id


PRODUCTO_COMPRAR = "hosting-basico"


def wc(self, method, path, **kw):
    """Llamada a la API REST de WooCommerce (/wp-json/wc/v3/...)."""
    base = self.api.rsplit("/wp/v2", 1)[0]
    r = self.s.request(method, f"{base}/{path}", timeout=60, **kw)
    if r.status_code >= 400:
        raise RuntimeError(f"{method} {path} -> {r.status_code}: {r.text[:300]}")
    return r.json() if r.text else {}


def buy_url(self, product_slug):
    """URL de la página de pago con el producto agregado al carrito."""
    cache = getattr(self, "_buy_cache", {})
    if product_slug in cache:
        return cache[product_slug]
    prods = self.wc("GET", "wc/v3/products", params={"slug": product_slug, "status": "any"})
    if not prods:
        raise RuntimeError(f"no existe el producto '{product_slug}' (corre el modo setup)")
    checkout_id = self.wc("GET", "wc/v3/settings/advanced/woocommerce_checkout_page_id").get("value")
    link = self.req("GET", f"pages/{checkout_id}", params={"context": "edit"})["link"] if checkout_id else None
    if not link:
        raise RuntimeError("WooCommerce no tiene página de pago configurada")
    sep = "&" if "?" in link else "?"
    url = f"{link}{sep}add-to-cart={prods[0]['id']}"
    cache[product_slug] = url
    self._buy_cache = cache
    return url


WP.wc = wc
WP.buy_url = buy_url


INTERNAL_HOSTS = ("hostingmexico.org", "www.hostingmexico.org")
_A_TAG = re.compile(r"<a\s[^>]*>", re.I)


def nofollow_external(html):
    """Regla del sitio: todo enlace externo lleva rel="nofollow". Los internos (/ruta/ o hostingmexico.org) no."""
    def fix(m):
        tag = m.group(0)
        href = re.search(r'href=["\']([^"\']+)["\']', tag, re.I)
        if not href or not re.match(r"(?i)https?://", href.group(1)):
            return tag
        host = re.sub(r"(?i)^https?://", "", href.group(1)).split("/")[0].split(":")[0].lower()
        if host in INTERNAL_HOSTS:
            return tag
        rel = re.search(r'rel=["\']([^"\']*)["\']', tag, re.I)
        if rel:
            vals = rel.group(1).split()
            if "nofollow" in (v.lower() for v in vals):
                return tag
            return tag[:rel.start()] + f'rel="{" ".join(vals + ["nofollow"])}"' + tag[rel.end():]
        return tag[:-1].rstrip("/").rstrip() + ' rel="nofollow">'
    return _A_TAG.sub(fix, html)


def parse(path):
    text = path.read_text(encoding="utf-8")
    if not text.startswith("---"):
        raise ValueError(f"{path}: falta frontmatter YAML")
    _, fm, body = text.split("---", 2)
    meta = yaml.safe_load(fm) or {}
    for field in ("title", "slug"):
        if not meta.get(field):
            raise ValueError(f"{path}: falta '{field}' en el frontmatter")
    status = meta.get("status", "draft")
    if status not in VALID_STATUS:
        raise ValueError(f"{path}: status inválido '{status}'")
    if meta.get("format") == "blocks":
        # El cuerpo ya viene en marcado de bloques de Gutenberg: se publica tal cual.
        html = body.strip()
    else:
        html = markdown.markdown(
            body.strip(),
            extensions=["tables", "fenced_code", "sane_lists", "toc", "attr_list", "md_in_html"],
            extension_configs={"toc": {"toc_depth": "2-3", "title": "Índice de contenidos"}},
        )
    return meta, nofollow_external(html)


def resolve_placeholders(wp, html):
    """{{comprar}} -> página de pago con el producto ya en el carrito (slug en PRODUCTO_COMPRAR)."""
    if "{{comprar}}" not in html:
        return html
    try:
        url = wp.buy_url(PRODUCTO_COMPRAR)
    except Exception as e:  # noqa: BLE001
        print(f"  Aviso: no pude armar el enlace de compra ({e}); uso /tienda/ temporalmente.")
        url = "/tienda/"
    return html.replace("{{comprar}}", url)


def publish(wp, path):
    meta, html = parse(path)
    html = resolve_placeholders(wp, html)
    kind = meta.get("type", "post")
    endpoint = "pages" if kind == "page" else "posts"
    payload = {
        "title": meta["title"],
        "slug": meta["slug"],
        "content": html,
        "status": meta.get("status", "draft"),
        # Regla del sitio: sin comentarios ni pingbacks en ninguna entrada o página.
        "comment_status": "closed",
        "ping_status": "closed",
    }
    if meta.get("excerpt"):
        payload["excerpt"] = meta["excerpt"]
    if meta.get("template"):
        payload["template"] = meta["template"]
    if meta.get("date"):
        # Fecha local de Ciudad de México (UTC-6, sin horario de verano desde 2022).
        local = datetime.datetime.fromisoformat(str(meta["date"]))
        gmt = local + datetime.timedelta(hours=6)
        payload["date_gmt"] = gmt.strftime("%Y-%m-%dT%H:%M:%S")
        if payload["status"] == "publish" and gmt > datetime.datetime.now(datetime.timezone.utc).replace(tzinfo=None):
            payload["status"] = "future"
    if kind == "post":
        if meta.get("categories"):
            payload["categories"] = [wp.term_id("categories", c) for c in meta["categories"]]
        if meta.get("tags"):
            payload["tags"] = [wp.term_id("tags", t, by="name") for t in meta["tags"]]
    elif meta.get("parent"):
        parent = wp.find_by_slug("pages", meta["parent"])
        if parent:
            payload["parent"] = parent["id"]
    if meta.get("featured_image"):
        payload["featured_media"] = wp.media_id(
            ROOT / meta["featured_image"], meta.get("featured_alt", meta["title"]))

    # Con "id" en el frontmatter se actualiza esa entrada aunque cambie el slug;
    # WordPress guarda el slug anterior y redirige la URL vieja a la nueva.
    existing = {"id": int(meta["id"])} if meta.get("id") else wp.find_by_slug(endpoint, meta["slug"])
    if existing:
        res = wp.req("POST", f"{endpoint}/{existing['id']}", json=payload)
        action = "actualizada"
    else:
        res = wp.req("POST", endpoint, json=payload)
        action = "creada"
    print(f"{path.relative_to(ROOT)}: {kind} {action} ({res['status']}) id={res['id']} {res['link']}")


def main():
    ap = argparse.ArgumentParser()
    ap.add_argument("files", nargs="*")
    ap.add_argument("--all", action="store_true", help="publicar todo content/")
    ap.add_argument("--check", action="store_true", help="solo validar credenciales")
    args = ap.parse_args()

    wp = WP(env("WP_URL"), env("WP_USER"), env("WP_APP_PASSWORD"))
    me = wp.whoami()
    print(f"Conectado como {me.get('username', me.get('name'))} (roles: {', '.join(me.get('roles', []))})")
    if args.check:
        return

    files = sorted(CONTENT_DIR.rglob("*.md")) if args.all else [ROOT / f for f in args.files]
    files = [f for f in files if f.exists() and f.suffix == ".md" and CONTENT_DIR in f.resolve().parents]
    if not files:
        print("Nada que publicar.")
        return

    errors = 0
    for f in files:
        try:
            publish(wp, f)
        except Exception as e:  # noqa: BLE001
            errors += 1
            print(f"ERROR {f.relative_to(ROOT)}: {e}")
    if errors:
        sys.exit(f"{errors} archivo(s) con error")


if __name__ == "__main__":
    main()

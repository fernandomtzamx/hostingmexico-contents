#!/usr/bin/env python3
"""Aplica docs/sitio.yml en hostingmexico.org vía API REST.

Pasos: ajustes generales, plugins, WooCommerce, producto de hosting, estado del
plugin WHM, contenido de ejemplo a la papelera y, si publicar: true, portada,
menú, pie de página y salida del modo "Próximamente".
"""
import html
import importlib.util
import pathlib
import sys

import yaml

ROOT = pathlib.Path(__file__).resolve().parent.parent
spec = importlib.util.spec_from_file_location("publish", ROOT / "scripts" / "publish.py")
pub = importlib.util.module_from_spec(spec)
spec.loader.exec_module(pub)

errors = 0


def step(name):
    print(f"\n== {name}")


def fail(msg):
    global errors
    errors += 1
    print(f"ERROR {msg}")


def ajustes(wp, cfg):
    step("Ajustes del sitio")
    s = cfg.get("sitio") or {}
    payload = {k: v for k, v in {
        "title": s.get("title"),
        "description": s.get("description"),
        "timezone": s.get("timezone"),
        "language": s.get("language"),
        "date_format": s.get("date_format"),
        "start_of_week": s.get("start_of_week"),
    }.items() if v is not None}
    try:
        res = wp.req("POST", "settings", json=payload)
        print(f"Título: {res.get('title')} | Zona: {res.get('timezone')} | Idioma: {res.get('language')}")
        if s.get("language") and res.get("language") != s.get("language"):
            print(f"! El idioma {s.get('language')} no está instalado: elígelo en Ajustes > Generales > Idioma del sitio "
                  "(WordPress lo descarga al guardar) y luego actualiza traducciones en Escritorio > Actualizaciones.")
    except Exception as e:  # noqa: BLE001
        if "language" in payload and "language" in str(e):
            payload.pop("language")
            res = wp.req("POST", "settings", json=payload)
            print(f"Título: {res.get('title')} | Zona: {res.get('timezone')}")
            print(f"! El idioma {s.get('language')} no está instalado: elígelo en Ajustes > Generales > Idioma del sitio.")
        else:
            fail(f"ajustes: {e}")


def paginas_woocommerce(wp, cfg):
    step("Páginas de WooCommerce")
    groups = {"shop": "products"}
    for key, want in (cfg.get("paginas_woocommerce") or {}).items():
        try:
            pid = wp.wc("GET", f"wc/v3/settings/{groups.get(key, 'advanced')}/woocommerce_{key}_page_id").get("value")
            if not pid:
                print(f"! WooCommerce no tiene página para {key}")
                continue
            page = wp.req("GET", f"pages/{pid}", params={"context": "edit"})
            if page["slug"] == want["slug"] and page["title"]["raw"] == want["title"]:
                print(f"= {key}: /{want['slug']}/")
                continue
            wp.req("POST", f"pages/{pid}", json={"slug": want["slug"], "title": want["title"]})
            print(f"+ {key}: /{page['slug']}/ -> /{want['slug']}/ ({want['title']})")
        except Exception as e:  # noqa: BLE001
            fail(f"página {key}: {e}")


def plugins(wp, cfg):
    step("Plugins")
    try:
        installed = {p["plugin"].split("/")[0]: p for p in wp.req("GET", "plugins")}
    except Exception as e:  # noqa: BLE001
        fail(f"no pude listar plugins (¿el usuario es administrador?): {e}")
        return {}
    for slug in cfg.get("plugins") or []:
        try:
            p = installed.get(slug)
            if not p:
                p = wp.req("POST", "plugins", json={"slug": slug, "status": "active"})
                print(f"+ instalado y activado: {slug} {p.get('version', '')}")
            elif p["status"] != "active":
                p = wp.req("POST", f"plugins/{p['plugin']}", json={"status": "active"})
                print(f"+ activado: {slug}")
            else:
                print(f"= ya activo: {slug} {p.get('version', '')}")
            installed[slug] = p
        except Exception as e:  # noqa: BLE001
            fail(f"plugin {slug}: {e}")
    hm = installed.get("hm-whm")
    if not hm:
        print("! Falta el plugin hm-whm (Hosting México: WHM). Súbelo en Plugins > Añadir nuevo > Subir plugin.")
    elif hm["status"] != "active":
        try:
            wp.req("POST", f"plugins/{hm['plugin']}", json={"status": "active"})
            print("+ activado: hm-whm")
        except Exception as e:  # noqa: BLE001
            fail(f"activar hm-whm: {e}")
    else:
        print(f"= ya activo: hm-whm {hm.get('version', '')}")
    return installed


def woocommerce(wp, cfg):
    step("WooCommerce")
    for group, opts in (cfg.get("woocommerce") or {}).items():
        for key, value in opts.items():
            try:
                cur = wp.wc("GET", f"wc/v3/settings/{group}/{key}")
                if str(cur.get("value")) == str(value):
                    print(f"= {key}: {value}")
                    continue
                wp.wc("PUT", f"wc/v3/settings/{group}/{key}", json={"value": value})
                print(f"+ {key}: {cur.get('value')} -> {value}")
            except Exception as e:  # noqa: BLE001
                fail(f"ajuste {group}/{key}: {e}")


def producto(wp, cfg, publicar):
    step("Producto")
    p = cfg.get("producto")
    if not p:
        return
    payload = {
        "name": p["nombre"],
        "slug": p["slug"],
        "type": "simple",
        "regular_price": str(p["precio"]),
        "virtual": True,
        "sold_individually": True,
        "status": "publish" if publicar else "draft",
        "catalog_visibility": "visible",
        "short_description": p.get("descripcion_corta", ""),
        "description": p.get("descripcion", ""),
        "reviews_allowed": False,
        "meta_data": [{"key": "_hm_whm_package", "value": p["paquete_whm"]}],
    }
    try:
        found = wp.wc("GET", "wc/v3/products", params={"slug": p["slug"], "status": "any"})
        if found:
            res = wp.wc("PUT", f"wc/v3/products/{found[0]['id']}", json=payload)
            action = "actualizado"
        else:
            res = wp.wc("POST", "wc/v3/products", json=payload)
            action = "creado"
        print(f"Producto {action}: {res['name']} ${res['regular_price']} ({res['status']}) id={res['id']} {res['permalink']}")
        print(f"Enlace de compra: {wp.buy_url(p['slug'])}")
    except Exception as e:  # noqa: BLE001
        fail(f"producto: {e}")


def whm(wp):
    step("Plugin WHM")
    base = wp.api.rsplit("/wp/v2", 1)[0]
    try:
        r = wp.s.get(f"{base}/hm-whm/v1/estado", timeout=120)
    except Exception as e:  # noqa: BLE001
        fail(f"estado WHM: {e}")
        return
    if r.status_code == 404:
        print("! El plugin hm-whm no está activo todavía.")
        return
    if r.status_code >= 400:
        fail(f"estado WHM -> {r.status_code}: {r.text[:200]}")
        return
    st = r.json()
    print(f"Plugin {st['plugin']} | configurado: {'sí' if st['configured'] else 'NO'} | "
          f"WHM: {st['whm_version'] or '-'} | paquete {st['package']}: "
          f"{'existe' if st['package_exists'] else 'no existe' if st['package_exists'] is False else '-'}")
    print(f"Servicios: {st['services']} | próxima revisión diaria: {st['next_daily']}")
    if st.get("error"):
        print(f"! {st['error']}")
    if st["whm_ok"] and st["package_exists"] is False:
        r = wp.s.post(f"{base}/hm-whm/v1/preparar", timeout=120)
        print(f"Crear paquete en WHM -> {r.status_code}: {r.text[:200]}")


def borrar(wp, cfg):
    step("Contenido de ejemplo a la papelera")
    for b in cfg.get("borrar") or []:
        tipo = b.get("tipo", "pages")
        try:
            cur = wp.req("GET", f"{tipo}/{b['id']}", params={"context": "edit"})
        except Exception:  # noqa: BLE001
            print(f"= ya no existe {tipo}/{b['id']} ({b['slug']})")
            continue
        if cur.get("status") == "trash":
            print(f"= ya en papelera: {tipo}/{b['id']} ({b['slug']})")
            continue
        slug = cur.get("slug") or cur.get("generated_slug", "")
        if not slug.startswith(b["slug"]):
            fail(f"{tipo}/{b['id']}: el slug es '{slug}', no '{b['slug']}'. No se borra.")
            continue
        try:
            wp.req("DELETE", f"{tipo}/{b['id']}")
            print(f"- a la papelera: {tipo}/{b['id']} ({slug})")
        except Exception as e:  # noqa: BLE001
            fail(f"borrar {tipo}/{b['id']}: {e}")


def nav_links(items):
    out = []
    for it in items:
        out.append('<!-- wp:navigation-link {"label":"%s","url":"%s","kind":"custom"} /-->'
                   % (html.escape(it["label"]), html.escape(it["url"])))
    return "\n".join(out)


def lanzamiento(wp, cfg):
    step("Lanzamiento (publicar: true)")
    # Portada
    try:
        inicio = wp.find_by_slug("pages", "inicio")
        if inicio and inicio["status"] == "publish":
            wp.req("POST", "settings", json={"show_on_front": "page", "page_on_front": inicio["id"]})
            print(f"Portada: página inicio (id {inicio['id']})")
        else:
            print("! La página inicio no está publicada; no se cambió la portada.")
    except Exception as e:  # noqa: BLE001
        fail(f"portada: {e}")

    # Página de términos que enlaza el checkout
    try:
        terms = wp.find_by_slug("pages", "terminos-y-condiciones")
        if terms:
            wp.wc("PUT", "wc/v3/settings/advanced/woocommerce_terms_page_id", json={"value": str(terms["id"])})
            print(f"Términos del checkout: página {terms['id']}")
    except Exception as e:  # noqa: BLE001
        fail(f"página de términos: {e}")

    theme = None
    try:
        theme = next(t for t in wp.req("GET", "themes", params={"status": "active"}))["stylesheet"]
    except Exception as e:  # noqa: BLE001
        fail(f"tema activo: {e}")

    # Menú principal y encabezado que lo usa (con botón "Contratar").
    try:
        content = nav_links(cfg.get("menu") or [])
        menus = wp.req("GET", "navigation", params={"search": "Menú principal", "status": "publish,draft"})
        menus = [m for m in menus if (m.get("title", {}).get("rendered") or "") == "Menú principal"]
        if menus:
            menu_id = menus[0]["id"]
            wp.req("POST", f"navigation/{menu_id}", json={"content": content, "status": "publish"})
            print(f"Menú actualizado (id {menu_id})")
        else:
            menu_id = wp.req("POST", "navigation", json={"title": "Menú principal", "content": content, "status": "publish"})["id"]
            print(f"Menú creado (id {menu_id})")
        try:
            buy = wp.buy_url((cfg.get("producto") or {}).get("slug", pub.PRODUCTO_COMPRAR))
        except Exception:  # noqa: BLE001
            buy = "/tienda/"
        header = (
            '<!-- wp:group {"align":"full","layout":{"type":"default"}} -->\n<div class="wp-block-group alignfull">'
            '<!-- wp:group {"layout":{"type":"constrained"}} -->\n<div class="wp-block-group">'
            '<!-- wp:group {"align":"wide","style":{"spacing":{"padding":{"top":"var:preset|spacing|30","bottom":"var:preset|spacing|30"}}},"layout":{"type":"flex","flexWrap":"nowrap","justifyContent":"space-between"}} -->\n'
            '<div class="wp-block-group alignwide" style="padding-top:var(--wp--preset--spacing--30);padding-bottom:var(--wp--preset--spacing--30)">'
            '<!-- wp:site-title {"level":0} /-->\n'
            '<!-- wp:group {"style":{"spacing":{"blockGap":"var:preset|spacing|20"}},"layout":{"type":"flex","flexWrap":"nowrap","justifyContent":"right"}} -->\n'
            '<div class="wp-block-group">'
            f'<!-- wp:navigation {{"ref":{menu_id},"overlayBackgroundColor":"base","overlayTextColor":"contrast","layout":{{"type":"flex","justifyContent":"right","flexWrap":"wrap"}}}} /-->\n'
            '<!-- wp:buttons -->\n<div class="wp-block-buttons"><!-- wp:button {"fontSize":"small"} -->\n'
            f'<div class="wp-block-button has-custom-font-size has-small-font-size"><a class="wp-block-button__link wp-element-button" href="{html.escape(buy)}">Contratar</a></div>\n'
            '<!-- /wp:button --></div>\n<!-- /wp:buttons --></div>\n<!-- /wp:group --></div>\n<!-- /wp:group --></div>\n'
            '<!-- /wp:group --></div>\n<!-- /wp:group -->'
        )
        if theme:
            wp.req("POST", f"template-parts/{theme}//header", json={"content": header})
            print(f"Encabezado actualizado ({theme}) con el menú {menu_id}")
    except Exception as e:  # noqa: BLE001
        fail(f"menú/encabezado: {e}")

    # Pie de página del tema activo
    try:
        if not theme:
            raise RuntimeError("sin tema activo")
        title = html.escape((cfg.get("sitio") or {}).get("title", ""))
        links = "\n".join(
            '<!-- wp:navigation-link {"label":"%s","url":"%s","kind":"custom"} /-->' % (html.escape(l["label"]), html.escape(l["url"]))
            for l in cfg.get("pie") or [])
        footer = (
            '<!-- wp:group {"style":{"spacing":{"padding":{"top":"var:preset|spacing|60","bottom":"var:preset|spacing|50"}}},"layout":{"type":"constrained"}} -->\n'
            '<div class="wp-block-group" style="padding-top:var(--wp--preset--spacing--60);padding-bottom:var(--wp--preset--spacing--50)">'
            '<!-- wp:group {"align":"wide","layout":{"type":"flex","flexWrap":"wrap","justifyContent":"space-between"}} -->\n'
            '<div class="wp-block-group alignwide"><!-- wp:site-title {"level":0} /-->\n\n'
            '<!-- wp:navigation {"overlayMenu":"never","layout":{"type":"flex","orientation":"horizontal","flexWrap":"wrap"},"fontSize":"small"} -->\n'
            f'{links}\n<!-- /wp:navigation --></div>\n<!-- /wp:group -->\n\n'
            '<!-- wp:paragraph {"fontSize":"small"} -->\n'
            f'<p class="has-small-font-size">© 2026 {title}. Precios en pesos mexicanos, IVA incluido. Soporte: <a href="mailto:soporte@hostingmexico.org">soporte@hostingmexico.org</a></p>\n'
            '<!-- /wp:paragraph --></div>\n<!-- /wp:group -->'
        )
        wp.req("POST", f"template-parts/{theme}//footer", json={"content": footer})
        print(f"Pie de página actualizado ({theme})")
    except Exception as e:  # noqa: BLE001
        fail(f"pie de página: {e}")

    # Quitar el modo "Próximamente" de WooCommerce
    try:
        wp.wc("PUT", "wc/v3/settings/site-visibility/woocommerce_coming_soon", json={"value": "no"})
        print("Tienda visible: modo Próximamente desactivado")
    except Exception as e:  # noqa: BLE001
        try:
            wp.req("POST", "settings", json={"woocommerce_coming_soon": "no"})
            print("Tienda visible: modo Próximamente desactivado")
        except Exception:  # noqa: BLE001
            print(f"! No pude desactivar 'Próximamente' por API ({str(e)[:120]}). "
                  "Hazlo en WooCommerce > Ajustes > Visibilidad del sitio.")


def main():
    cfg = yaml.safe_load((ROOT / "docs" / "sitio.yml").read_text(encoding="utf-8"))
    publicar = bool(cfg.get("publicar"))
    wp = pub.WP(pub.env("WP_URL"), pub.env("WP_USER"), pub.env("WP_APP_PASSWORD"))
    me = wp.whoami()
    print(f"Conectado como {me.get('username')} (roles: {', '.join(me.get('roles', []))}) | publicar: {publicar}")

    ajustes(wp, cfg)
    plugins(wp, cfg)
    woocommerce(wp, cfg)
    paginas_woocommerce(wp, cfg)
    producto(wp, cfg, publicar)
    whm(wp)
    borrar(wp, cfg)
    if publicar:
        lanzamiento(wp, cfg)

    if errors:
        sys.exit(f"\n{errors} error(es)")
    print("\nConfiguración aplicada.")


if __name__ == "__main__":
    main()

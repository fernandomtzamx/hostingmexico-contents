<?php
/**
 * Plugin Name: Hosting México: Google Tag Manager
 * Description: Carga el contenedor de Google Tag Manager al inicio del <head> en todo el sitio público (incluida la página de pago) y el respaldo noscript tras abrir <body>. GA4 y demás etiquetas se configuran dentro de GTM.
 * Version: 1.0
 *
 * Instalación: subir a wp-content/mu-plugins/hostingmexico-gtm.php (se activa solo).
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

const HOSTINGMEXICO_GTM_ID = 'GTM-5PTRT2J';

/** Solo en el sitio público: nada en el admin, la API, feeds ni vistas previas del personalizador. */
function hostingmexico_gtm_activo() {
	if ( is_admin() || is_feed() || is_customize_preview() ) {
		return false;
	}
	if ( defined( 'REST_REQUEST' ) && REST_REQUEST ) {
		return false;
	}
	return true;
}

// Script principal, lo más arriba posible del <head>.
add_action(
	'wp_head',
	function () {
		if ( ! hostingmexico_gtm_activo() ) {
			return;
		}
		$id = esc_js( HOSTINGMEXICO_GTM_ID );
		echo "<!-- hm-gtm: head -->\n";
		echo "<!-- Google Tag Manager -->\n";
		echo "<script>(function(w,d,s,l,i){w[l]=w[l]||[];w[l].push({'gtm.start':\n";
		echo "new Date().getTime(),event:'gtm.js'});var f=d.getElementsByTagName(s)[0],\n";
		echo "j=d.createElement(s),dl=l!='dataLayer'?'&l='+l:'';j.async=true;j.src=\n";
		echo "'https://www.googletagmanager.com/gtm.js?id='+i+dl;f.parentNode.insertBefore(j,f);\n";
		echo "})(window,document,'script','dataLayer','{$id}');</script>\n";
		echo "<!-- End Google Tag Manager -->\n";
	},
	1
);

// Respaldo noscript, justo después de abrir <body>.
add_action(
	'wp_body_open',
	function () {
		if ( ! hostingmexico_gtm_activo() ) {
			return;
		}
		$id = esc_attr( HOSTINGMEXICO_GTM_ID );
		echo "<!-- Google Tag Manager (noscript) -->\n";
		echo "<noscript><iframe src=\"https://www.googletagmanager.com/ns.html?id={$id}\" height=\"0\" width=\"0\" style=\"display:none;visibility:hidden\"></iframe></noscript>\n";
		echo "<!-- End Google Tag Manager (noscript) -->\n";
	},
	1
);

// Evita doble carga: oculta cualquier bloque HTML del tema que también inserte GTM
// (la versión temporal que se puso en el encabezado antes de este plugin).
add_filter(
	'render_block',
	function ( $content, $block ) {
		if ( isset( $block['blockName'] ) && 'core/html' === $block['blockName'] && false !== strpos( (string) $content, 'googletagmanager.com/gtm.js' ) ) {
			return '';
		}
		return $content;
	},
	10,
	2
);

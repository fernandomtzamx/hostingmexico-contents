<?php
/**
 * Plugin Name: Agente: auth para API REST
 * Description: Permite que el agente de hostingmexico.org publique por la API REST con contraseña de aplicación en servidores que borran el encabezado Authorization (LiteSpeed, FastCGI) o con plugins que consultan el usuario antes de tiempo. No abre acceso nuevo: sin contraseña de aplicación válida todo sigue rechazado.
 * Version: 1.1
 *
 * Instalación: subir a wp-content/mu-plugins/agente-auth.php (se activa solo).
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// 1. Credencial alterna: X-Agente-Auth (o X-Publimerca-Auth) lleva el mismo "Basic base64(usuario:contraseña)".
if ( empty( $_SERVER['PHP_AUTH_USER'] ) ) {
	$agente_header = '';
	foreach ( array( 'HTTP_X_AGENTE_AUTH', 'HTTP_X_PUBLIMERCA_AUTH', 'HTTP_AUTHORIZATION', 'REDIRECT_HTTP_AUTHORIZATION' ) as $agente_key ) {
		if ( ! empty( $_SERVER[ $agente_key ] ) ) {
			$agente_header = $_SERVER[ $agente_key ];
			break;
		}
	}
	$agente_header = trim( (string) $agente_header );
	if ( 0 === stripos( $agente_header, 'basic ' ) ) {
		$agente_decoded = base64_decode( substr( $agente_header, 6 ), true );
		if ( false !== $agente_decoded && false !== strpos( $agente_decoded, ':' ) ) {
			list( $agente_user, $agente_pass ) = explode( ':', $agente_decoded, 2 );
			$_SERVER['PHP_AUTH_USER'] = $agente_user;
			$_SERVER['PHP_AUTH_PW']   = $agente_pass;
		}
	}
}

// 2. Reconocer peticiones a la API REST desde el inicio, aunque otro plugin
//    consulte el usuario actual antes de que WordPress defina REST_REQUEST.
add_filter(
	'application_password_is_api_request',
	function ( $is_api_request ) {
		if ( $is_api_request ) {
			return true;
		}
		$uri = isset( $_SERVER['REQUEST_URI'] ) ? (string) $_SERVER['REQUEST_URI'] : '';
		return false !== strpos( $uri, '/wp-json/' ) || isset( $_GET['rest_route'] );
	}
);

// 3. Si alguien ya fijó el usuario como "anónimo" antes de tiempo, recalcularlo
//    al iniciar la API REST, cuando la contraseña de aplicación sí se evalúa.
add_action(
	'rest_api_init',
	function () {
		if ( ! empty( $_SERVER['PHP_AUTH_USER'] ) && 0 === get_current_user_id() ) {
			$GLOBALS['current_user'] = null;
			wp_get_current_user();
		}
	},
	0
);

<?php
/**
 * Plugin Name:       Hosting México: WHM
 * Description:       Vende hosting con WooCommerce: crea la cuenta cPanel al confirmarse el pago, avisa de renovaciones, suspende por falta de pago y reactiva al renovar, todo vía la API de WHM.
 * Version:           1.1.0
 * Requires at least: 6.4
 * Requires PHP:      7.4
 * Requires Plugins:  woocommerce
 * Author:            hostingmexico.org
 * Text Domain:       hm-whm
 */

defined( 'ABSPATH' ) || exit;

define( 'HM_WHM_VERSION', '1.1.0' );
define( 'HM_WHM_FILE', __FILE__ );
define( 'HM_WHM_CPT', 'hm_servicio' );

/* =========================================================================
 * Configuración
 * Prioridad: constante en wp-config.php (HM_WHM_HOST, HM_WHM_USER, HM_WHM_TOKEN...)
 * y luego WooCommerce > Hosting WHM.
 * ====================================================================== */

function hm_whm_defaults() {
	return array(
		'host'         => '',          // servidor.tudominio.com (puerto 2087 por defecto)
		'user'         => 'root',      // root o un revendedor
		'token'        => '',          // token de API de WHM
		'verify_ssl'   => 1,
		'package'      => 'hm_basico', // nombre del paquete en WHM
		'ns1'          => 'ns1.hostingmexico.org',
		'ns2'          => 'ns2.hostingmexico.org',
		'grace_days'   => 7,           // días de gracia tras vencer antes de suspender
		'notify_days'  => '15,3,0',    // días antes del vencimiento en que se avisa
		'delete_after' => 30,          // días suspendida tras los que se avisa al admin
		'admin_email'  => '',
	);
}

function hm_whm_opt( $key ) {
	$const = 'HM_WHM_' . strtoupper( $key );
	if ( defined( $const ) ) {
		return constant( $const );
	}
	$opts = wp_parse_args( (array) get_option( 'hm_whm', array() ), hm_whm_defaults() );
	if ( 'admin_email' === $key && empty( $opts['admin_email'] ) ) {
		return get_option( 'admin_email' );
	}
	return isset( $opts[ $key ] ) ? $opts[ $key ] : '';
}

/** Especificaciones del paquete que se crea en WHM si no existe. */
function hm_whm_package_specs() {
	return apply_filters(
		'hm_whm_package_specs',
		array(
			'quota'               => 20480,  // MB (20 GB)
			'bwlimit'             => 256000, // MB al mes (250 GB)
			'maxpop'              => 10,
			'maxsql'              => 5,
			'maxsub'              => 5,
			'maxaddon'            => 0,
			'maxpark'             => 1,
			'maxftp'              => 5,
			'maxlst'              => 0,
			'hasshell'            => 0,
			'cgi'                 => 1,
			'max_email_per_hour'  => 200,
			'max_emailacct_quota' => 2048,
		)
	);
}

/* =========================================================================
 * Cliente de la API de WHM (API 1)
 * ====================================================================== */

final class HM_WHM_API {

	public static function base_url() {
		$host = trim( (string) hm_whm_opt( 'host' ) );
		$host = preg_replace( '#^https?://#i', '', rtrim( $host, '/' ) );
		if ( '' !== $host && false === strpos( $host, ':' ) ) {
			$host .= ':2087';
		}
		return apply_filters( 'hm_whm_api_base', $host ? 'https://' . $host : '' );
	}

	/**
	 * @return array|WP_Error  data de la respuesta
	 */
	public static function call( $function, array $args = array() ) {
		$base  = self::base_url();
		$token = trim( (string) hm_whm_opt( 'token' ) );
		$user  = trim( (string) hm_whm_opt( 'user' ) );
		if ( '' === $base || '' === $token ) {
			return new WP_Error( 'hm_whm_config', 'Falta configurar el servidor WHM (host y token) en WooCommerce > Hosting WHM.' );
		}
		$args['api.version'] = 1;
		$response = wp_remote_post(
			$base . '/json-api/' . rawurlencode( $function ),
			array(
				'timeout'   => 90,
				'sslverify' => (bool) hm_whm_opt( 'verify_ssl' ),
				'headers'   => array( 'Authorization' => 'whm ' . $user . ':' . $token ),
				'body'      => $args,
			)
		);
		if ( is_wp_error( $response ) ) {
			return new WP_Error( 'hm_whm_http', 'No se pudo conectar con WHM: ' . $response->get_error_message() );
		}
		$code = (int) wp_remote_retrieve_response_code( $response );
		$body = json_decode( wp_remote_retrieve_body( $response ), true );
		if ( 403 === $code || 401 === $code ) {
			return new WP_Error( 'hm_whm_auth', 'WHM rechazó el token (HTTP ' . $code . '). Revisa usuario y token.' );
		}
		if ( 200 !== $code || ! is_array( $body ) ) {
			return new WP_Error( 'hm_whm_http', sprintf( 'WHM respondió HTTP %d en %s.', $code, $function ) );
		}
		if ( empty( $body['metadata']['result'] ) ) {
			$reason = isset( $body['metadata']['reason'] ) ? $body['metadata']['reason'] : 'error desconocido';
			return new WP_Error( 'hm_whm_api', $function . ': ' . wp_strip_all_tags( $reason ) );
		}
		return isset( $body['data'] ) ? (array) $body['data'] : array();
	}

	/** Nombre del paquete tal como lo ve WHM (los revendedores llevan prefijo usuario_). */
	public static function package_name( $base = '' ) {
		$base = $base ? $base : hm_whm_opt( 'package' );
		$user = hm_whm_opt( 'user' );
		if ( 'root' !== $user && 0 !== strpos( $base, $user . '_' ) ) {
			return $user . '_' . $base;
		}
		return $base;
	}

	public static function package_exists( $base = '' ) {
		$data = self::call( 'listpkgs' );
		if ( is_wp_error( $data ) ) {
			return $data;
		}
		$want = array( $base ? $base : hm_whm_opt( 'package' ), self::package_name( $base ) );
		foreach ( (array) ( isset( $data['pkg'] ) ? $data['pkg'] : array() ) as $pkg ) {
			if ( isset( $pkg['name'] ) && in_array( $pkg['name'], $want, true ) ) {
				return true;
			}
		}
		return false;
	}

	public static function ensure_package( $base = '' ) {
		$exists = self::package_exists( $base );
		if ( is_wp_error( $exists ) || $exists ) {
			return $exists;
		}
		$args = array_merge( array( 'name' => $base ? $base : hm_whm_opt( 'package' ) ), hm_whm_package_specs() );
		$r    = self::call( 'addpkg', $args );
		return is_wp_error( $r ) ? $r : true;
	}
}

/* =========================================================================
 * Utilidades
 * ====================================================================== */

function hm_whm_clean_domain( $value ) {
	$d = strtolower( trim( (string) $value ) );
	$d = preg_replace( '#^[a-z]+://#', '', $d );
	$d = preg_replace( '#[/?\#].*$#', '', $d );
	$d = preg_replace( '/:\d+$/', '', $d );
	$d = preg_replace( '/^www\./', '', $d );
	return rtrim( $d, '.' );
}

function hm_whm_valid_domain( $domain ) {
	return (bool) preg_match( '/^(?=.{4,253}$)(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z]{2,63}$/', $domain );
}

function hm_whm_password() {
	// Mezcla garantizada de mayúsculas, minúsculas, números y símbolos (pasa el medidor de cPanel).
	$sets = array( 'ABCDEFGHJKLMNPQRSTUVWXYZ', 'abcdefghijkmnopqrstuvwxyz', '23456789', '!@#%^*-_=+' );
	$pass = '';
	foreach ( $sets as $set ) {
		$pass .= $set[ random_int( 0, strlen( $set ) - 1 ) ];
	}
	$all = implode( '', $sets );
	for ( $i = 0; $i < 14; $i++ ) {
		$pass .= $all[ random_int( 0, strlen( $all ) - 1 ) ];
	}
	return str_shuffle( $pass );
}

/** Genera un usuario de cPanel disponible a partir del dominio. */
function hm_whm_username( $domain ) {
	$base = preg_replace( '/[^a-z0-9]/', '', strtolower( strtok( $domain, '.' ) ) );
	if ( '' === $base || ! ctype_alpha( $base[0] ) ) {
		$base = 'hm' . $base;
	}
	if ( 0 === strpos( $base, 'test' ) ) {
		$base = 'hm' . $base; // cPanel no permite usuarios que empiecen con "test".
	}
	$base = substr( $base, 0, 8 );
	for ( $i = 0; $i < 30; $i++ ) {
		$suffix = $i ? (string) ( $i + 1 ) : '';
		$cand   = substr( $base, 0, 8 - strlen( $suffix ) ) . $suffix;
		$r      = HM_WHM_API::call( 'verify_new_username', array( 'user' => $cand ) );
		if ( ! is_wp_error( $r ) ) {
			return $cand;
		}
		if ( 'hm_whm_api' !== $r->get_error_code() ) {
			return $r; // error de conexión o de token: no seguir probando.
		}
	}
	return new WP_Error( 'hm_whm_user', 'No se encontró un nombre de usuario disponible para ' . $domain . '.' );
}

function hm_whm_cpanel_url() {
	$host = preg_replace( '#^https?://#i', '', rtrim( (string) hm_whm_opt( 'host' ), '/' ) );
	$host = preg_replace( '/:\d+$/', '', $host );
	return $host ? 'https://' . $host . ':2083' : '';
}

function hm_whm_mail( $to, $subject, $heading, $html ) {
	if ( function_exists( 'WC' ) && WC()->mailer() ) {
		$mailer = WC()->mailer();
		return $mailer->send( $to, $subject, $mailer->wrap_message( $heading, $html ) );
	}
	return wp_mail( $to, $subject, $html, array( 'Content-Type: text/html; charset=UTF-8' ) );
}

function hm_whm_mail_admin( $subject, $html ) {
	return hm_whm_mail( hm_whm_opt( 'admin_email' ), '[Hosting WHM] ' . $subject, $subject, $html );
}

function hm_whm_date( $ts ) {
	return wp_date( 'j \d\e F \d\e Y', (int) $ts );
}

function hm_whm_log( $sid, $msg ) {
	$log   = (array) get_post_meta( $sid, '_hm_log', true );
	$log[] = array( 't' => time(), 'm' => $msg );
	update_post_meta( $sid, '_hm_log', array_slice( $log, -50 ) );
}

/* =========================================================================
 * Servicios (tipo de contenido privado: una entrada por cuenta de hosting)
 * ====================================================================== */

add_action(
	'init',
	function () {
		register_post_type(
			HM_WHM_CPT,
			array(
				'labels'          => array(
					'name'          => 'Servicios de hosting',
					'singular_name' => 'Servicio de hosting',
					'menu_name'     => 'Servicios de hosting',
					'all_items'     => 'Servicios de hosting',
					'edit_item'     => 'Servicio de hosting',
					'search_items'  => 'Buscar servicios',
					'not_found'     => 'Aún no hay servicios.',
				),
				'public'          => false,
				'show_ui'         => true,
				'show_in_menu'    => 'woocommerce',
				'supports'        => array( 'title' ),
				'capability_type' => 'post',
				'capabilities'    => array(
					'create_posts'       => 'do_not_allow',
					'edit_post'          => 'manage_woocommerce',
					'read_post'          => 'manage_woocommerce',
					'delete_post'        => 'manage_woocommerce',
					'edit_posts'         => 'manage_woocommerce',
					'edit_others_posts'  => 'manage_woocommerce',
					'delete_posts'       => 'manage_woocommerce',
					'publish_posts'      => 'manage_woocommerce',
					'read_private_posts' => 'manage_woocommerce',
				),
				'map_meta_cap'    => false,
			)
		);
		if ( get_option( 'hm_whm_flush' ) ) {
			flush_rewrite_rules();
			delete_option( 'hm_whm_flush' );
		}
	}
);

function hm_whm_service( $sid ) {
	$p = get_post( $sid );
	if ( ! $p || HM_WHM_CPT !== $p->post_type ) {
		return null;
	}
	return array(
		'id'       => $p->ID,
		'domain'   => get_post_meta( $sid, '_hm_domain', true ),
		'user'     => get_post_meta( $sid, '_hm_user', true ),
		'package'  => get_post_meta( $sid, '_hm_package', true ),
		'expires'  => (int) get_post_meta( $sid, '_hm_expires', true ),
		'status'   => get_post_meta( $sid, '_hm_status', true ),
		'customer' => (int) get_post_meta( $sid, '_hm_customer', true ),
		'order'    => (int) get_post_meta( $sid, '_hm_order', true ),
		'product'  => (int) get_post_meta( $sid, '_hm_product', true ),
		'email'    => get_post_meta( $sid, '_hm_email', true ),
	);
}

function hm_whm_services_by( $meta_key, $value, $statuses = array() ) {
	$meta = array( array( 'key' => $meta_key, 'value' => $value ) );
	if ( $statuses ) {
		$meta[] = array( 'key' => '_hm_status', 'value' => $statuses, 'compare' => 'IN' );
	}
	return get_posts(
		array(
			'post_type'      => HM_WHM_CPT,
			'post_status'    => 'any',
			'posts_per_page' => -1,
			'fields'         => 'ids',
			'meta_query'     => $meta,
		)
	);
}

$GLOBALS['hm_whm_status_labels'] = array(
	'activo'     => 'Activo',
	'suspendido' => 'Suspendido',
	'cancelado'  => 'Cancelado',
);

/* =========================================================================
 * Campo "Dominio" en el checkout (bloques y clásico)
 * ====================================================================== */

add_action(
	'woocommerce_init',
	function () {
		if ( ! function_exists( 'woocommerce_register_additional_checkout_field' ) ) {
			return;
		}
		woocommerce_register_additional_checkout_field(
			array(
				'id'                => 'hm/dominio',
				'label'             => 'Dominio de tu sitio (ej. tunegocio.com)',
				'location'          => 'order',
				'type'              => 'text',
				'required'          => true,
				'attributes'        => array(
					'autocomplete'   => 'off',
					'autocapitalize' => 'none',
				),
				'sanitize_callback' => function ( $value ) {
					return hm_whm_clean_domain( $value );
				},
				'validate_callback' => function ( $value ) {
					return hm_whm_validate_domain_for_checkout( $value );
				},
			)
		);
	}
);

/** @return true|WP_Error */
function hm_whm_validate_domain_for_checkout( $value ) {
	$domain = hm_whm_clean_domain( $value );
	if ( ! hm_whm_valid_domain( $domain ) ) {
		return new WP_Error( 'hm_dominio', 'Escribe un dominio válido, por ejemplo tunegocio.com.' );
	}
	if ( hm_whm_services_by( '_hm_domain', $domain, array( 'activo', 'suspendido' ) ) ) {
		return new WP_Error( 'hm_dominio', 'Ese dominio ya tiene un hosting con nosotros. Si es tuyo, renuévalo desde Mi cuenta > Mis hostings.' );
	}
	return true;
}

// Checkout clásico (shortcode).
add_filter(
	'woocommerce_checkout_fields',
	function ( $fields ) {
		$fields['order'] = array_merge(
			array(
				'hm_dominio' => array(
					'type'        => 'text',
					'label'       => 'Dominio de tu sitio',
					'placeholder' => 'tunegocio.com',
					'required'    => true,
					'priority'    => 1,
				),
			),
			isset( $fields['order'] ) ? $fields['order'] : array()
		);
		return $fields;
	}
);

add_action(
	'woocommerce_after_checkout_validation',
	function ( $data, $errors ) {
		if ( ! isset( $data['hm_dominio'] ) || '' === $data['hm_dominio'] ) {
			return;
		}
		$ok = hm_whm_validate_domain_for_checkout( $data['hm_dominio'] );
		if ( is_wp_error( $ok ) ) {
			$errors->add( 'hm_dominio', $ok->get_error_message() );
		}
	},
	10,
	2
);

add_action(
	'woocommerce_checkout_create_order',
	function ( $order, $data ) {
		if ( ! empty( $data['hm_dominio'] ) ) {
			$order->update_meta_data( '_hm_dominio', hm_whm_clean_domain( $data['hm_dominio'] ) );
		}
	},
	10,
	2
);

function hm_whm_order_domain( $order ) {
	$d = $order->get_meta( '_hm_dominio' );
	if ( ! $d && class_exists( '\Automattic\WooCommerce\Blocks\Package' ) ) {
		try {
			$fields = \Automattic\WooCommerce\Blocks\Package::container()->get( \Automattic\WooCommerce\Blocks\Domain\Services\CheckoutFields::class );
			$d      = $fields->get_field_from_object( 'hm/dominio', $order, 'other' );
		} catch ( \Throwable $e ) {
			$d = '';
		}
	}
	if ( ! $d ) {
		$d = $order->get_meta( '_wc_other/hm/dominio' );
	}
	return hm_whm_clean_domain( $d );
}

/* =========================================================================
 * Checkout mínimo: solo nombre, correo y dominio
 * La tienda vende solo hosting (sin envío), así que la dirección no se pide.
 * ====================================================================== */

function hm_whm_hidden_address_fields() {
	return apply_filters( 'hm_whm_hidden_address_fields', array( 'company', 'address_1', 'address_2', 'city', 'state', 'postcode', 'phone' ) );
}

function hm_whm_hide_locale_fields( $fields ) {
	foreach ( hm_whm_hidden_address_fields() as $key ) {
		$fields[ $key ] = array_merge( isset( $fields[ $key ] ) ? (array) $fields[ $key ] : array(), array( 'required' => false, 'hidden' => true ) );
	}
	return $fields;
}

// Checkout de bloques: usa la configuración regional para ocultar y no exigir campos.
add_filter( 'woocommerce_get_country_locale_default', 'hm_whm_hide_locale_fields', 99 );
add_filter(
	'woocommerce_get_country_locale',
	function ( $locales ) {
		foreach ( $locales as $country => $fields ) {
			$locales[ $country ] = hm_whm_hide_locale_fields( (array) $fields );
		}
		if ( ! isset( $locales['MX'] ) ) {
			$locales['MX'] = hm_whm_hide_locale_fields( array() );
		}
		return $locales;
	},
	99
);

// Checkout clásico.
add_filter(
	'woocommerce_billing_fields',
	function ( $fields ) {
		foreach ( hm_whm_hidden_address_fields() as $key ) {
			unset( $fields[ 'billing_' . $key ] );
		}
		return $fields;
	},
	99
);
add_filter( 'woocommerce_enable_order_notes_field', '__return_false' );

// Los botones "Contratar" agregan el plan al carrito: si ya está, seguir al pago sin mostrar error.
add_filter(
	'woocommerce_add_to_cart_validation',
	function ( $passed, $product_id ) {
		if ( ! $passed || ! WC()->cart ) {
			return $passed;
		}
		$product = wc_get_product( $product_id );
		if ( $product && $product->is_sold_individually() && WC()->cart->find_product_in_cart( WC()->cart->generate_cart_id( $product_id ) ) ) {
			return false;
		}
		return $passed;
	},
	1,
	2
);

// Si solo se vende a un país, el selector de país no aporta nada: se oculta (el valor ya viene fijo).
add_action(
	'wp_enqueue_scripts',
	function () {
		if ( ! function_exists( 'is_checkout' ) || ! is_checkout() || ! WC()->countries ) {
			return;
		}
		if ( 1 === count( WC()->countries->get_allowed_countries() ) ) {
			wp_register_style( 'hm-whm-checkout', false, array(), HM_WHM_VERSION );
			wp_enqueue_style( 'hm-whm-checkout' );
			wp_add_inline_style( 'hm-whm-checkout', '.wc-block-checkout .wc-block-components-address-form__country,#billing_country_field{display:none!important}' );
		}
	}
);

// Si el país no llega (campo oculto en algún tema), usar México.
add_action(
	'woocommerce_checkout_create_order',
	function ( $order ) {
		if ( ! $order->get_billing_country() ) {
			$order->set_billing_country( 'MX' );
		}
	},
	5
);

/* =========================================================================
 * Producto: campo "Paquete WHM"
 * ====================================================================== */

add_action(
	'woocommerce_product_options_general_product_data',
	function () {
		woocommerce_wp_text_input(
			array(
				'id'          => '_hm_whm_package',
				'label'       => 'Paquete WHM',
				'description' => 'Si se llena, al pagarse este producto se crea una cuenta cPanel con este paquete (ej. hm_basico). Vigencia: 1 año.',
				'desc_tip'    => true,
			)
		);
	}
);

add_action(
	'woocommerce_admin_process_product_object',
	function ( $product ) {
		if ( isset( $_POST['_hm_whm_package'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification
			$product->update_meta_data( '_hm_whm_package', sanitize_key( wp_unslash( $_POST['_hm_whm_package'] ) ) ); // phpcs:ignore
		}
	}
);

function hm_whm_order_has_hosting( $order ) {
	foreach ( $order->get_items() as $item ) {
		$p = $item->get_product();
		if ( $p && $p->get_meta( '_hm_whm_package' ) ) {
			return true;
		}
	}
	return (bool) $order->get_meta( '_hm_renueva' );
}

/* =========================================================================
 * Pedido pagado: aprovisionar o renovar
 * ====================================================================== */

function hm_whm_on_paid( $order_id ) {
	$order = wc_get_order( $order_id );
	if ( ! $order || ! hm_whm_order_has_hosting( $order ) ) {
		return;
	}
	if ( function_exists( 'as_enqueue_async_action' ) ) {
		if ( ! as_has_scheduled_action( 'hm_whm_process_order', array( (int) $order_id ), 'hm-whm' ) ) {
			as_enqueue_async_action( 'hm_whm_process_order', array( (int) $order_id ), 'hm-whm' );
		}
	} else {
		hm_whm_process_order( $order_id );
	}
}
add_action( 'woocommerce_order_status_processing', 'hm_whm_on_paid' );
add_action( 'woocommerce_order_status_completed', 'hm_whm_on_paid' );
add_action( 'hm_whm_process_order', 'hm_whm_process_order' );

// Los pedidos que solo traen hosting se completan solos (no hay nada que enviar).
add_filter(
	'woocommerce_payment_complete_order_status',
	function ( $status, $order_id, $order ) {
		if ( 'processing' === $status && $order && hm_whm_order_has_hosting( $order ) ) {
			foreach ( $order->get_items() as $item ) {
				$p = $item->get_product();
				if ( $p && ! $p->is_virtual() ) {
					return $status;
				}
			}
			return 'completed';
		}
		return $status;
	},
	10,
	3
);

function hm_whm_process_order( $order_id ) {
	$order = wc_get_order( $order_id );
	if ( ! $order || ! $order->has_status( array( 'processing', 'completed' ) ) ) {
		return;
	}
	$lock = 'hm_whm_lock_' . $order->get_id();
	if ( ! add_option( $lock, time(), '', 'no' ) ) {
		if ( time() - (int) get_option( $lock ) < 600 ) {
			return; // otro proceso lo está atendiendo.
		}
		update_option( $lock, time(), 'no' );
	}
	try {
		$sid = (int) $order->get_meta( '_hm_renueva' );
		if ( $sid ) {
			hm_whm_apply_renewal( $order, $sid );
		} else {
			foreach ( $order->get_items() as $item ) {
				$product = $item->get_product();
				$pkg     = $product ? $product->get_meta( '_hm_whm_package' ) : '';
				if ( $pkg && ! $item->get_meta( '_hm_servicio' ) ) {
					hm_whm_provision( $order, $item, $product, $pkg );
				}
			}
		}
	} finally {
		delete_option( $lock );
	}
}

function hm_whm_provision( $order, $item, $product, $pkg ) {
	$domain = hm_whm_order_domain( $order );
	$fail   = function ( $msg ) use ( $order, $domain ) {
		$order->update_meta_data( '_hm_error', $msg );
		$order->save();
		$order->add_order_note( 'Hosting: no se pudo crear la cuenta. ' . $msg . ' Corrige y usa la acción "Crear cuenta de hosting (reintentar)".' );
		hm_whm_mail_admin(
			'Error al crear cuenta de ' . ( $domain ? $domain : 'pedido #' . $order->get_order_number() ),
			'<p>Pedido <a href="' . esc_url( $order->get_edit_order_url() ) . '">#' . esc_html( $order->get_order_number() ) . '</a> pagado, pero WHM no creó la cuenta:</p><p><strong>' . esc_html( $msg ) . '</strong></p><p>Corrígelo y reintenta desde el pedido (Acciones del pedido &gt; Crear cuenta de hosting).</p>'
		);
	};

	if ( ! hm_whm_valid_domain( $domain ) ) {
		$fail( 'El pedido no tiene un dominio válido (' . ( $domain ? $domain : 'vacío' ) . ').' );
		return;
	}
	$user = hm_whm_username( $domain );
	if ( is_wp_error( $user ) ) {
		$fail( $user->get_error_message() );
		return;
	}
	$pass = hm_whm_password();
	$r    = HM_WHM_API::call(
		'createacct',
		array(
			'username'     => $user,
			'domain'       => $domain,
			'plan'         => HM_WHM_API::package_name( $pkg ),
			'contactemail' => $order->get_billing_email(),
			'password'     => $pass,
		)
	);
	if ( is_wp_error( $r ) ) {
		$fail( $r->get_error_message() );
		return;
	}

	$sid = wp_insert_post(
		array(
			'post_type'   => HM_WHM_CPT,
			'post_status' => 'publish',
			'post_title'  => $domain,
		),
		true
	);
	if ( is_wp_error( $sid ) ) {
		$fail( 'La cuenta ' . $user . ' SÍ se creó en WHM, pero no se pudo registrar en WordPress: ' . $sid->get_error_message() );
		return;
	}
	$expires = strtotime( '+1 year', time() );
	foreach (
		array(
			'_hm_domain'   => $domain,
			'_hm_user'     => $user,
			'_hm_package'  => $pkg,
			'_hm_expires'  => $expires,
			'_hm_status'   => 'activo',
			'_hm_customer' => $order->get_customer_id(),
			'_hm_email'    => $order->get_billing_email(),
			'_hm_order'    => $order->get_id(),
			'_hm_product'  => $product->get_id(),
		) as $k => $v
	) {
		update_post_meta( $sid, $k, $v );
	}
	hm_whm_log( $sid, 'Cuenta creada en WHM con el pedido #' . $order->get_order_number() . '.' );

	$item->update_meta_data( '_hm_servicio', $sid );
	$item->update_meta_data( 'Dominio', $domain );
	$item->save();
	$order->delete_meta_data( '_hm_error' );
	$order->save();
	$order->add_order_note( sprintf( 'Hosting: cuenta cPanel "%s" creada para %s. Vence el %s.', $user, $domain, hm_whm_date( $expires ) ) );

	hm_whm_mail_welcome( $order, $sid, $pass );

	if ( $order->has_status( 'processing' ) ) {
		$order->update_status( 'completed' );
	}
}

function hm_whm_mail_welcome( $order, $sid, $pass ) {
	$s    = hm_whm_service( $sid );
	$name = $order->get_billing_first_name();
	$html = '<p>Hola' . ( $name ? ' ' . esc_html( $name ) : '' ) . ',</p>'
		. '<p>Tu hosting para <strong>' . esc_html( $s['domain'] ) . '</strong> ya está listo. Estos son tus datos de acceso:</p>'
		. '<table cellpadding="6" style="border-collapse:collapse">'
		. '<tr><td><strong>Panel cPanel</strong></td><td><a href="' . esc_url( hm_whm_cpanel_url() ) . '">' . esc_html( hm_whm_cpanel_url() ) . '</a></td></tr>'
		. '<tr><td><strong>Usuario</strong></td><td>' . esc_html( $s['user'] ) . '</td></tr>'
		. '<tr><td><strong>Contraseña</strong></td><td><code>' . esc_html( $pass ) . '</code></td></tr>'
		. '<tr><td><strong>DNS 1</strong></td><td>' . esc_html( hm_whm_opt( 'ns1' ) ) . '</td></tr>'
		. '<tr><td><strong>DNS 2</strong></td><td>' . esc_html( hm_whm_opt( 'ns2' ) ) . '</td></tr>'
		. '<tr><td><strong>Vence</strong></td><td>' . esc_html( hm_whm_date( $s['expires'] ) ) . '</td></tr>'
		. '</table>'
		. '<p><strong>Siguiente paso:</strong> en el panel de tu registrador de dominio cambia los servidores DNS por los de arriba. El cambio suele reflejarse en unas horas.</p>'
		. '<p>También puedes entrar a cPanel sin contraseña desde <a href="' . esc_url( wc_get_account_endpoint_url( 'mis-hostings' ) ) . '">Mi cuenta &gt; Mis hostings</a>. Te recomendamos cambiar la contraseña la primera vez que entres.</p>'
		. '<p>¿Dudas? Responde a este correo.</p>';
	hm_whm_mail( $order->get_billing_email(), 'Tu hosting para ' . $s['domain'] . ' está listo', 'Tu hosting está listo', $html );
}

/* =========================================================================
 * Renovaciones
 * ====================================================================== */

/** Devuelve el pedido de renovación pendiente del servicio o crea uno. */
function hm_whm_renewal_order( $sid ) {
	$s = hm_whm_service( $sid );
	if ( ! $s ) {
		return new WP_Error( 'hm_whm_service', 'Servicio no encontrado.' );
	}
	$pending = (int) get_post_meta( $sid, '_hm_renewal_order', true );
	if ( $pending ) {
		$o = wc_get_order( $pending );
		if ( $o && $o->has_status( array( 'pending', 'failed', 'on-hold' ) ) ) {
			return $o;
		}
	}
	$product = wc_get_product( $s['product'] );
	if ( ! $product ) {
		return new WP_Error( 'hm_whm_product', 'El producto original del servicio ya no existe.' );
	}
	$order = wc_create_order( array( 'customer_id' => $s['customer'] ) );
	if ( is_wp_error( $order ) ) {
		return $order;
	}
	$first = wc_get_order( $s['order'] );
	if ( $first ) {
		$order->set_address( $first->get_address( 'billing' ), 'billing' );
	} else {
		$order->set_billing_email( $s['email'] );
	}
	$item_id = $order->add_product( $product, 1 );
	$item    = $item_id ? $order->get_item( $item_id ) : null;
	if ( $item ) {
		$item->add_meta_data( 'Dominio', $s['domain'], true );
		$item->add_meta_data( 'Renovación', '1 año', true );
		$item->save();
	}
	$order->update_meta_data( '_hm_renueva', $sid );
	$order->calculate_totals();
	$order->set_status( 'pending' );
	$order->save();
	$order->add_order_note( 'Renovación de hosting para ' . $s['domain'] . '.' );
	update_post_meta( $sid, '_hm_renewal_order', $order->get_id() );
	hm_whm_log( $sid, 'Pedido de renovación #' . $order->get_order_number() . ' creado.' );
	return $order;
}

function hm_whm_apply_renewal( $order, $sid ) {
	if ( $order->get_meta( '_hm_renovado' ) ) {
		return;
	}
	$s = hm_whm_service( $sid );
	if ( ! $s ) {
		$order->add_order_note( 'Hosting: el servicio a renovar ya no existe.' );
		return;
	}
	if ( 'suspendido' === $s['status'] ) {
		$r = HM_WHM_API::call( 'unsuspendacct', array( 'user' => $s['user'] ) );
		if ( is_wp_error( $r ) ) {
			$order->add_order_note( 'Hosting: renovación pagada, pero no se pudo reactivar la cuenta: ' . $r->get_error_message() );
			hm_whm_mail_admin( 'No se pudo reactivar ' . $s['domain'], '<p>El cliente pagó la renovación (pedido #' . esc_html( $order->get_order_number() ) . ') pero WHM respondió: <strong>' . esc_html( $r->get_error_message() ) . '</strong>.</p><p>Reintenta desde el pedido.</p>' );
			return;
		}
		hm_whm_log( $sid, 'Cuenta reactivada en WHM.' );
	}
	$new = strtotime( '+1 year', max( $s['expires'], time() ) );
	update_post_meta( $sid, '_hm_expires', $new );
	update_post_meta( $sid, '_hm_status', 'activo' );
	delete_post_meta( $sid, '_hm_renewal_order' );
	delete_post_meta( $sid, '_hm_avisos' );
	hm_whm_log( $sid, 'Renovado con el pedido #' . $order->get_order_number() . ' hasta ' . hm_whm_date( $new ) . '.' );

	$order->update_meta_data( '_hm_renovado', $new );
	$order->save();
	$order->add_order_note( 'Hosting: ' . $s['domain'] . ' renovado hasta el ' . hm_whm_date( $new ) . '.' );
	hm_whm_mail(
		$order->get_billing_email(),
		'Renovamos tu hosting de ' . $s['domain'],
		'Renovación confirmada',
		'<p>Recibimos tu pago. Tu hosting para <strong>' . esc_html( $s['domain'] ) . '</strong> ahora vence el <strong>' . esc_html( hm_whm_date( $new ) ) . '</strong>.</p>'
		. ( 'suspendido' === $s['status'] ? '<p>Tu cuenta ya está activa de nuevo.</p>' : '' )
		. '<p>Gracias por seguir con nosotros.</p>'
	);
	if ( $order->has_status( 'processing' ) ) {
		$order->update_status( 'completed' );
	}
}

/* =========================================================================
 * Tarea diaria: avisos, suspensiones y alertas
 * ====================================================================== */

add_action( 'hm_whm_daily', 'hm_whm_daily' );

function hm_whm_daily() {
	$ids = get_posts(
		array(
			'post_type'      => HM_WHM_CPT,
			'post_status'    => 'any',
			'posts_per_page' => -1,
			'fields'         => 'ids',
			'meta_query'     => array( array( 'key' => '_hm_status', 'value' => array( 'activo', 'suspendido' ), 'compare' => 'IN' ) ),
		)
	);
	$now     = time();
	$grace   = max( 0, (int) hm_whm_opt( 'grace_days' ) );
	$notify  = array_map( 'intval', array_filter( array_map( 'trim', explode( ',', (string) hm_whm_opt( 'notify_days' ) ) ), 'strlen' ) );
	rsort( $notify );
	$summary = array();

	foreach ( $ids as $sid ) {
		$s = hm_whm_service( $sid );
		if ( ! $s || ! $s['expires'] ) {
			continue;
		}
		$days = (int) floor( ( $s['expires'] - $now ) / DAY_IN_SECONDS );

		if ( 'activo' === $s['status'] ) {
			// Suspender si se acabó la gracia.
			if ( $now > $s['expires'] + $grace * DAY_IN_SECONDS ) {
				$r = HM_WHM_API::call( 'suspendacct', array( 'user' => $s['user'], 'reason' => 'Falta de pago de renovación' ) );
				if ( is_wp_error( $r ) ) {
					$summary[] = 'ERROR al suspender ' . $s['domain'] . ': ' . $r->get_error_message();
					continue;
				}
				update_post_meta( $sid, '_hm_status', 'suspendido' );
				update_post_meta( $sid, '_hm_suspended', $now );
				hm_whm_log( $sid, 'Suspendido por falta de pago.' );
				$summary[] = 'Suspendido: ' . $s['domain'];
				$order     = hm_whm_renewal_order( $sid );
				$pay       = is_wp_error( $order ) ? wc_get_account_endpoint_url( 'mis-hostings' ) : $order->get_checkout_payment_url();
				hm_whm_mail(
					$s['email'],
					'Suspendimos el hosting de ' . $s['domain'],
					'Tu hosting está suspendido',
					'<p>Tu hosting para <strong>' . esc_html( $s['domain'] ) . '</strong> venció el ' . esc_html( hm_whm_date( $s['expires'] ) ) . ' y no recibimos la renovación, así que lo suspendimos. Tus archivos siguen guardados.</p>'
					. '<p>Para reactivarlo al instante, <a href="' . esc_url( $pay ) . '">paga la renovación aquí</a>.</p>'
					. '<p>Si la cuenta sigue suspendida ' . (int) hm_whm_opt( 'delete_after' ) . ' días, podría eliminarse de forma definitiva.</p>'
				);
				continue;
			}
			// Avisos de renovación: se envía el umbral más cercano alcanzado, una vez por periodo.
			$sent = get_post_meta( $sid, '_hm_avisos', true );
			$sent = is_array( $sent ) ? array_map( 'intval', $sent ) : array();
			foreach ( array_reverse( $notify ) as $t ) {
				if ( $days > $t ) {
					continue;
				}
				if ( in_array( $t, $sent, true ) ) {
					break;
				}
				$order = hm_whm_renewal_order( $sid );
				if ( is_wp_error( $order ) ) {
					$summary[] = 'ERROR al crear la renovación de ' . $s['domain'] . ': ' . $order->get_error_message();
					break;
				}
				$when = $days > 0 ? 'vence en ' . $days . ' día' . ( 1 === $days ? '' : 's' ) : ( $days === 0 ? 'vence hoy' : 'venció el ' . hm_whm_date( $s['expires'] ) );
				hm_whm_mail(
					$s['email'],
					'Tu hosting de ' . $s['domain'] . ' ' . $when,
					'Renueva tu hosting',
					'<p>Tu hosting para <strong>' . esc_html( $s['domain'] ) . '</strong> ' . esc_html( $when ) . ' (' . esc_html( hm_whm_date( $s['expires'] ) ) . ').</p>'
					. '<p><a href="' . esc_url( $order->get_checkout_payment_url() ) . '" style="display:inline-block;padding:12px 20px;background:#111;color:#fff;text-decoration:none;border-radius:6px">Renovar por ' . wp_strip_all_tags( wc_price( $order->get_total() ) ) . '</a></p>'
					. '<p>El año nuevo se suma desde tu fecha de vencimiento, así que no pierdes días por renovar antes. Si no se renueva, la cuenta se suspende ' . $grace . ' días después del vencimiento.</p>'
				);
				// Marca este umbral y todos los mayores como enviados.
				foreach ( $notify as $u ) {
					if ( $u >= $t && ! in_array( $u, $sent, true ) ) {
						$sent[] = $u;
					}
				}
				update_post_meta( $sid, '_hm_avisos', $sent );
				hm_whm_log( $sid, 'Aviso de renovación enviado (' . $when . ').' );
				$summary[] = 'Aviso enviado: ' . $s['domain'] . ' ' . $when;
				break;
			}
		} elseif ( 'suspendido' === $s['status'] ) {
			$limit = $s['expires'] + ( $grace + (int) hm_whm_opt( 'delete_after' ) ) * DAY_IN_SECONDS;
			if ( $now > $limit && ! get_post_meta( $sid, '_hm_alerta_eliminar', true ) ) {
				update_post_meta( $sid, '_hm_alerta_eliminar', $now );
				$summary[] = 'Lleva ' . (int) hm_whm_opt( 'delete_after' ) . ' días suspendido, considerar eliminar: ' . $s['domain'] . ' (usuario ' . $s['user'] . ')';
			}
		}
	}

	update_option( 'hm_whm_last_daily', array( 't' => $now, 'summary' => $summary ), 'no' );
	if ( $summary ) {
		hm_whm_mail_admin( 'Resumen diario de hosting', '<ul><li>' . implode( '</li><li>', array_map( 'esc_html', $summary ) ) . '</li></ul><p>Nota: el plugin nunca elimina cuentas; hazlo en WHM si corresponde.</p>' );
	}
}

/* =========================================================================
 * Mi cuenta > Mis hostings
 * ====================================================================== */

add_filter(
	'woocommerce_get_query_vars',
	function ( $vars ) {
		$vars['mis-hostings'] = 'mis-hostings';
		return $vars;
	}
);

add_filter(
	'woocommerce_account_menu_items',
	function ( $items ) {
		$new = array();
		foreach ( $items as $k => $v ) {
			$new[ $k ] = $v;
			if ( 'dashboard' === $k ) {
				$new['mis-hostings'] = 'Mis hostings';
			}
		}
		if ( ! isset( $new['mis-hostings'] ) ) {
			$new = array( 'mis-hostings' => 'Mis hostings' ) + $new;
		}
		return $new;
	}
);

add_filter(
	'woocommerce_endpoint_mis-hostings_title',
	function () {
		return 'Mis hostings';
	}
);

add_action(
	'woocommerce_account_mis-hostings_endpoint',
	function () {
		$ids = hm_whm_services_by( '_hm_customer', get_current_user_id(), array( 'activo', 'suspendido' ) );
		if ( ! $ids ) {
			echo '<p>Aún no tienes hostings contratados.</p>';
			return;
		}
		global $hm_whm_status_labels;
		echo '<table class="shop_table shop_table_responsive"><thead><tr><th>Dominio</th><th>Estado</th><th>Vence</th><th></th></tr></thead><tbody>';
		foreach ( $ids as $sid ) {
			$s     = hm_whm_service( $sid );
			$sso   = wp_nonce_url( admin_url( 'admin-post.php?action=hm_whm_sso&sid=' . $sid ), 'hm_whm_sso_' . $sid );
			$renew = wp_nonce_url( admin_url( 'admin-post.php?action=hm_whm_renovar&sid=' . $sid ), 'hm_whm_renovar_' . $sid );
			echo '<tr>';
			echo '<td data-title="Dominio"><strong>' . esc_html( $s['domain'] ) . '</strong><br><small>Usuario: ' . esc_html( $s['user'] ) . '</small></td>';
			echo '<td data-title="Estado">' . esc_html( isset( $hm_whm_status_labels[ $s['status'] ] ) ? $hm_whm_status_labels[ $s['status'] ] : $s['status'] ) . '</td>';
			echo '<td data-title="Vence">' . esc_html( hm_whm_date( $s['expires'] ) ) . '</td>';
			echo '<td>';
			if ( 'activo' === $s['status'] ) {
				echo '<a class="button" href="' . esc_url( $sso ) . '" target="_blank" rel="noopener">Entrar a cPanel</a> ';
			}
			echo '<a class="button" href="' . esc_url( $renew ) . '">Renovar</a>';
			echo '</td></tr>';
		}
		echo '</tbody></table>';
		echo '<p><small>DNS: ' . esc_html( hm_whm_opt( 'ns1' ) ) . ' y ' . esc_html( hm_whm_opt( 'ns2' ) ) . '</small></p>';
	}
);

function hm_whm_customer_service_or_die( $action ) {
	$sid = isset( $_GET['sid'] ) ? absint( $_GET['sid'] ) : 0; // phpcs:ignore
	if ( ! is_user_logged_in() || ! wp_verify_nonce( isset( $_GET['_wpnonce'] ) ? sanitize_text_field( wp_unslash( $_GET['_wpnonce'] ) ) : '', $action . '_' . $sid ) ) {
		wp_die( 'Enlace vencido. Vuelve a Mi cuenta e inténtalo de nuevo.', 403 );
	}
	$s = hm_whm_service( $sid );
	if ( ! $s || ( $s['customer'] !== get_current_user_id() && ! current_user_can( 'manage_woocommerce' ) ) ) {
		wp_die( 'Este servicio no pertenece a tu cuenta.', 403 );
	}
	return $s;
}

add_action(
	'admin_post_hm_whm_sso',
	function () {
		$s = hm_whm_customer_service_or_die( 'hm_whm_sso' );
		if ( 'activo' !== $s['status'] ) {
			wp_die( 'La cuenta está suspendida. Renuévala para volver a entrar.' );
		}
		$r = HM_WHM_API::call( 'create_user_session', array( 'user' => $s['user'], 'service' => 'cpaneld' ) );
		if ( is_wp_error( $r ) || empty( $r['url'] ) ) {
			wp_die( 'No pudimos abrir cPanel en este momento. Entra en ' . esc_html( hm_whm_cpanel_url() ) . ' con tu usuario y contraseña, o escríbenos.' );
		}
		wp_redirect( $r['url'] ); // phpcs:ignore WordPress.Security.SafeRedirect -- URL de sesión de cPanel.
		exit;
	}
);

add_action(
	'admin_post_hm_whm_renovar',
	function () {
		$s     = hm_whm_customer_service_or_die( 'hm_whm_renovar' );
		$order = hm_whm_renewal_order( $s['id'] );
		if ( is_wp_error( $order ) ) {
			wp_die( esc_html( $order->get_error_message() ) );
		}
		wp_safe_redirect( $order->get_checkout_payment_url() );
		exit;
	}
);

/* =========================================================================
 * Administración
 * ====================================================================== */

// Acción manual en el pedido.
add_filter(
	'woocommerce_order_actions',
	function ( $actions, $order = null ) {
		if ( ! $order || hm_whm_order_has_hosting( $order ) ) {
			$actions['hm_whm_reintentar'] = 'Crear cuenta de hosting (reintentar)';
		}
		return $actions;
	},
	10,
	2
);

add_action(
	'woocommerce_order_action_hm_whm_reintentar',
	function ( $order ) {
		if ( ! $order->has_status( array( 'processing', 'completed' ) ) ) {
			$order->add_order_note( 'Hosting: el pedido debe estar pagado (procesando o completado) para crear la cuenta.' );
			return;
		}
		hm_whm_process_order( $order->get_id() );
	}
);

// Columnas de la lista de servicios.
add_filter(
	'manage_' . HM_WHM_CPT . '_posts_columns',
	function () {
		return array(
			'cb'         => '<input type="checkbox" />',
			'title'      => 'Dominio',
			'hm_user'    => 'Usuario cPanel',
			'hm_cliente' => 'Cliente',
			'hm_vence'   => 'Vence',
			'hm_estado'  => 'Estado',
		);
	}
);

add_action(
	'manage_' . HM_WHM_CPT . '_posts_custom_column',
	function ( $col, $sid ) {
		global $hm_whm_status_labels;
		$s = hm_whm_service( $sid );
		switch ( $col ) {
			case 'hm_user':
				echo esc_html( $s['user'] );
				break;
			case 'hm_cliente':
				$u = $s['customer'] ? get_userdata( $s['customer'] ) : null;
				echo esc_html( $u ? $u->display_name . ' (' . $u->user_email . ')' : $s['email'] );
				break;
			case 'hm_vence':
				echo esc_html( hm_whm_date( $s['expires'] ) );
				break;
			case 'hm_estado':
				echo esc_html( isset( $hm_whm_status_labels[ $s['status'] ] ) ? $hm_whm_status_labels[ $s['status'] ] : $s['status'] );
				break;
		}
	},
	10,
	2
);

// Caja con datos y acciones en cada servicio.
add_action(
	'add_meta_boxes_' . HM_WHM_CPT,
	function () {
		add_meta_box( 'hm_whm_info', 'Cuenta de hosting', 'hm_whm_metabox', HM_WHM_CPT, 'normal', 'high' );
	}
);

function hm_whm_metabox( $post ) {
	global $hm_whm_status_labels;
	$s     = hm_whm_service( $post->ID );
	$order = $s['order'] ? wc_get_order( $s['order'] ) : null;
	echo '<table class="form-table"><tbody>';
	echo '<tr><th>Usuario cPanel</th><td>' . esc_html( $s['user'] ) . '</td></tr>';
	echo '<tr><th>Paquete</th><td>' . esc_html( $s['package'] ) . '</td></tr>';
	echo '<tr><th>Estado</th><td>' . esc_html( isset( $hm_whm_status_labels[ $s['status'] ] ) ? $hm_whm_status_labels[ $s['status'] ] : $s['status'] ) . '</td></tr>';
	echo '<tr><th>Vence</th><td>' . esc_html( hm_whm_date( $s['expires'] ) ) . '</td></tr>';
	echo '<tr><th>Correo</th><td>' . esc_html( $s['email'] ) . '</td></tr>';
	echo '<tr><th>Pedido original</th><td>' . ( $order ? '<a href="' . esc_url( $order->get_edit_order_url() ) . '">#' . esc_html( $order->get_order_number() ) . '</a>' : '-' ) . '</td></tr>';
	echo '</tbody></table><p>';
	$acts = array(
		'suspender' => 'Suspender en WHM',
		'reactivar' => 'Reactivar en WHM',
		'extender'  => 'Extender 1 año (sin cobro)',
		'aviso'     => 'Enviar enlace de renovación',
	);
	foreach ( $acts as $a => $label ) {
		$url = wp_nonce_url( admin_url( 'admin-post.php?action=hm_whm_admin&do=' . $a . '&sid=' . $post->ID ), 'hm_whm_admin_' . $post->ID );
		echo '<a class="button" href="' . esc_url( $url ) . '" onclick="return confirm(\'¿Confirmas: ' . esc_js( $label ) . '?\')">' . esc_html( $label ) . '</a> ';
	}
	echo '</p><h4>Historial</h4><ul>';
	foreach ( array_reverse( (array) get_post_meta( $post->ID, '_hm_log', true ) ) as $l ) {
		if ( is_array( $l ) ) {
			echo '<li>' . esc_html( wp_date( 'Y-m-d H:i', $l['t'] ) . ' · ' . $l['m'] ) . '</li>';
		}
	}
	echo '</ul>';
}

add_action(
	'admin_post_hm_whm_admin',
	function () {
		$sid = isset( $_GET['sid'] ) ? absint( $_GET['sid'] ) : 0;
		check_admin_referer( 'hm_whm_admin_' . $sid );
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( 'Sin permiso.', 403 );
		}
		$s  = hm_whm_service( $sid );
		$do = isset( $_GET['do'] ) ? sanitize_key( $_GET['do'] ) : '';
		$msg = '';
		if ( ! $s ) {
			wp_die( 'Servicio no encontrado.' );
		}
		switch ( $do ) {
			case 'suspender':
				$r = HM_WHM_API::call( 'suspendacct', array( 'user' => $s['user'], 'reason' => 'Suspensión manual' ) );
				if ( ! is_wp_error( $r ) ) {
					update_post_meta( $sid, '_hm_status', 'suspendido' );
					update_post_meta( $sid, '_hm_suspended', time() );
					hm_whm_log( $sid, 'Suspendido manualmente.' );
				}
				$msg = is_wp_error( $r ) ? $r->get_error_message() : 'Cuenta suspendida.';
				break;
			case 'reactivar':
				$r = HM_WHM_API::call( 'unsuspendacct', array( 'user' => $s['user'] ) );
				if ( ! is_wp_error( $r ) ) {
					update_post_meta( $sid, '_hm_status', 'activo' );
					hm_whm_log( $sid, 'Reactivado manualmente.' );
				}
				$msg = is_wp_error( $r ) ? $r->get_error_message() : 'Cuenta reactivada.';
				break;
			case 'extender':
				$new = strtotime( '+1 year', max( $s['expires'], time() ) );
				update_post_meta( $sid, '_hm_expires', $new );
				delete_post_meta( $sid, '_hm_avisos' );
				hm_whm_log( $sid, 'Extendido manualmente hasta ' . hm_whm_date( $new ) . '.' );
				$msg = 'Nueva fecha de vencimiento: ' . hm_whm_date( $new ) . '. Si estaba suspendido, reactívalo.';
				break;
			case 'aviso':
				$o = hm_whm_renewal_order( $sid );
				if ( ! is_wp_error( $o ) ) {
					hm_whm_mail( $s['email'], 'Renueva tu hosting de ' . $s['domain'], 'Renueva tu hosting', '<p>Puedes renovar tu hosting para <strong>' . esc_html( $s['domain'] ) . '</strong> (vence el ' . esc_html( hm_whm_date( $s['expires'] ) ) . ') en este enlace:</p><p><a href="' . esc_url( $o->get_checkout_payment_url() ) . '">Pagar renovación</a></p>' );
				}
				$msg = is_wp_error( $o ) ? $o->get_error_message() : 'Enlace enviado a ' . $s['email'] . '.';
				break;
		}
		set_transient( 'hm_whm_notice_' . get_current_user_id(), $msg, 60 );
		wp_safe_redirect( get_edit_post_link( $sid, 'raw' ) );
		exit;
	}
);

add_action(
	'admin_notices',
	function () {
		$k   = 'hm_whm_notice_' . get_current_user_id();
		$msg = get_transient( $k );
		if ( $msg ) {
			delete_transient( $k );
			echo '<div class="notice notice-info is-dismissible"><p><strong>Hosting WHM:</strong> ' . esc_html( $msg ) . '</p></div>';
		}
		if ( current_user_can( 'manage_woocommerce' ) && ( ! hm_whm_opt( 'host' ) || ! hm_whm_opt( 'token' ) ) ) {
			echo '<div class="notice notice-warning"><p><strong>Hosting WHM:</strong> falta conectar el servidor. <a href="' . esc_url( admin_url( 'admin.php?page=hm-whm' ) ) . '">Configurar</a></p></div>';
		}
	}
);

// Página de ajustes.
add_action(
	'admin_menu',
	function () {
		add_submenu_page( 'woocommerce', 'Hosting WHM', 'Hosting WHM', 'manage_woocommerce', 'hm-whm', 'hm_whm_settings_page' );
	},
	99
);

add_action(
	'admin_init',
	function () {
		register_setting(
			'hm_whm',
			'hm_whm',
			array(
				'type'              => 'array',
				'sanitize_callback' => function ( $in ) {
					$old = (array) get_option( 'hm_whm', array() );
					$out = array();
					foreach ( hm_whm_defaults() as $k => $def ) {
						$v = isset( $in[ $k ] ) ? $in[ $k ] : ( 'verify_ssl' === $k ? 0 : $def );
						if ( 'token' === $k && '' === trim( (string) $v ) && ! empty( $old['token'] ) ) {
							$v = $old['token']; // dejar vacío conserva el token guardado.
						}
						$out[ $k ] = is_int( $def ) ? absint( $v ) : sanitize_text_field( $v );
					}
					$out['package'] = sanitize_key( $out['package'] );
					return $out;
				},
			)
		);
	}
);

function hm_whm_settings_page() {
	$fields = array(
		'host'         => array( 'Servidor WHM', 'servidor.tudominio.com (puerto 2087 por defecto)' ),
		'user'         => array( 'Usuario WHM', 'root o tu usuario revendedor' ),
		'token'        => array( 'Token de API', 'WHM > Development > Manage API Tokens. Déjalo vacío para conservar el actual.' ),
		'verify_ssl'   => array( 'Verificar SSL', 'Desmarca solo si WHM usa un certificado autofirmado.' ),
		'package'      => array( 'Paquete por defecto', 'Se crea en WHM con "Preparar paquete" si no existe.' ),
		'ns1'          => array( 'DNS 1', '' ),
		'ns2'          => array( 'DNS 2', '' ),
		'grace_days'   => array( 'Días de gracia', 'Días después de vencer antes de suspender.' ),
		'notify_days'  => array( 'Avisos (días antes)', 'Separados por coma.' ),
		'delete_after' => array( 'Alerta de eliminación', 'Días suspendida tras los que te avisamos para eliminarla a mano.' ),
		'admin_email'  => array( 'Correo de alertas', 'Recibe errores y el resumen diario.' ),
	);
	echo '<div class="wrap"><h1>Hosting WHM</h1>';
	echo '<form method="post" action="options.php">';
	settings_fields( 'hm_whm' );
	echo '<table class="form-table">';
	foreach ( $fields as $k => $f ) {
		$const = 'HM_WHM_' . strtoupper( $k );
		$val   = hm_whm_opt( $k );
		echo '<tr><th><label for="hm_' . esc_attr( $k ) . '">' . esc_html( $f[0] ) . '</label></th><td>';
		if ( defined( $const ) ) {
			echo '<code>' . ( 'token' === $k ? '••••••' : esc_html( (string) $val ) ) . '</code> <em>(definido en wp-config.php)</em>';
		} elseif ( 'verify_ssl' === $k ) {
			echo '<input type="checkbox" id="hm_verify_ssl" name="hm_whm[verify_ssl]" value="1" ' . checked( (int) $val, 1, false ) . '>';
		} elseif ( 'token' === $k ) {
			echo '<input type="password" class="regular-text" id="hm_token" name="hm_whm[token]" value="" placeholder="' . ( $val ? '•••••••• (guardado)' : '' ) . '" autocomplete="new-password">';
		} else {
			echo '<input type="text" class="regular-text" id="hm_' . esc_attr( $k ) . '" name="hm_whm[' . esc_attr( $k ) . ']" value="' . esc_attr( (string) $val ) . '">';
		}
		if ( $f[1] ) {
			echo '<p class="description">' . esc_html( $f[1] ) . '</p>';
		}
		echo '</td></tr>';
	}
	echo '</table>';
	submit_button( 'Guardar' );
	echo '</form><hr>';

	$test = wp_nonce_url( admin_url( 'admin-post.php?action=hm_whm_test' ), 'hm_whm_test' );
	$prep = wp_nonce_url( admin_url( 'admin-post.php?action=hm_whm_prepare' ), 'hm_whm_prepare' );
	$run  = wp_nonce_url( admin_url( 'admin-post.php?action=hm_whm_run_daily' ), 'hm_whm_run_daily' );
	echo '<p><a class="button button-primary" href="' . esc_url( $test ) . '">Probar conexión</a> ';
	echo '<a class="button" href="' . esc_url( $prep ) . '">Preparar paquete en WHM</a> ';
	echo '<a class="button" href="' . esc_url( $run ) . '">Ejecutar revisión diaria ahora</a></p>';

	$specs = hm_whm_package_specs();
	echo '<p class="description">Paquete que se crea: ' . esc_html( round( $specs['quota'] / 1024 ) . ' GB de disco, ' . round( $specs['bwlimit'] / 1024 ) . ' GB de transferencia, ' . $specs['maxpop'] . ' correos, ' . $specs['maxsql'] . ' bases de datos, ' . $specs['maxsub'] . ' subdominios.' ) . '</p>';
	$last = get_option( 'hm_whm_last_daily' );
	if ( $last ) {
		echo '<p class="description">Última revisión diaria: ' . esc_html( wp_date( 'Y-m-d H:i', $last['t'] ) ) . ' (' . count( $last['summary'] ) . ' eventos).</p>';
	}
	echo '</div>';
}

add_action(
	'admin_post_hm_whm_test',
	function () {
		check_admin_referer( 'hm_whm_test' );
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( 'Sin permiso.', 403 );
		}
		$st = hm_whm_status();
		$msg = $st['whm_ok'] ? 'Conexión correcta con WHM ' . $st['whm_version'] . '. Paquete "' . $st['package'] . '": ' . ( $st['package_exists'] ? 'existe' : 'NO existe (usa "Preparar paquete")' ) . '.' : 'Error: ' . $st['error'];
		set_transient( 'hm_whm_notice_' . get_current_user_id(), $msg, 60 );
		wp_safe_redirect( admin_url( 'admin.php?page=hm-whm' ) );
		exit;
	}
);

add_action(
	'admin_post_hm_whm_prepare',
	function () {
		check_admin_referer( 'hm_whm_prepare' );
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( 'Sin permiso.', 403 );
		}
		$r = HM_WHM_API::ensure_package();
		set_transient( 'hm_whm_notice_' . get_current_user_id(), is_wp_error( $r ) ? 'Error: ' . $r->get_error_message() : 'El paquete ' . HM_WHM_API::package_name() . ' está listo en WHM.', 60 );
		wp_safe_redirect( admin_url( 'admin.php?page=hm-whm' ) );
		exit;
	}
);

add_action(
	'admin_post_hm_whm_run_daily',
	function () {
		check_admin_referer( 'hm_whm_run_daily' );
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( 'Sin permiso.', 403 );
		}
		hm_whm_daily();
		$last = get_option( 'hm_whm_last_daily' );
		set_transient( 'hm_whm_notice_' . get_current_user_id(), 'Revisión ejecutada: ' . ( $last['summary'] ? implode( ' | ', $last['summary'] ) : 'sin eventos.' ), 60 );
		wp_safe_redirect( admin_url( 'admin.php?page=hm-whm' ) );
		exit;
	}
);

/* =========================================================================
 * API REST para el agente (diagnóstico desde GitHub Actions)
 * ====================================================================== */

function hm_whm_status() {
	$out = array(
		'plugin'         => HM_WHM_VERSION,
		'configured'     => (bool) ( hm_whm_opt( 'host' ) && hm_whm_opt( 'token' ) ),
		'whm_ok'         => false,
		'whm_version'    => null,
		'package'        => HM_WHM_API::package_name(),
		'package_exists' => null,
		'services'       => array(),
		'next_daily'     => wp_next_scheduled( 'hm_whm_daily' ) ? wp_date( 'c', wp_next_scheduled( 'hm_whm_daily' ) ) : null,
		'error'          => null,
	);
	foreach ( array( 'activo', 'suspendido', 'cancelado' ) as $st ) {
		$out['services'][ $st ] = count( hm_whm_services_by( '_hm_status', $st ) );
	}
	if ( ! $out['configured'] ) {
		$out['error'] = 'Falta configurar host y token de WHM.';
		return $out;
	}
	$v = HM_WHM_API::call( 'version' );
	if ( is_wp_error( $v ) ) {
		$out['error'] = $v->get_error_message();
		return $out;
	}
	$out['whm_ok']      = true;
	$out['whm_version'] = isset( $v['version'] ) ? $v['version'] : '?';
	$p                  = HM_WHM_API::package_exists();
	$out['package_exists'] = is_wp_error( $p ) ? null : $p;
	if ( is_wp_error( $p ) ) {
		$out['error'] = $p->get_error_message();
	}
	return $out;
}

add_action(
	'rest_api_init',
	function () {
		$perm = function () {
			return current_user_can( 'manage_woocommerce' );
		};
		register_rest_route(
			'hm-whm/v1',
			'/estado',
			array(
				'methods'             => 'GET',
				'permission_callback' => $perm,
				'callback'            => 'hm_whm_status',
			)
		);
		register_rest_route(
			'hm-whm/v1',
			'/preparar',
			array(
				'methods'             => 'POST',
				'permission_callback' => $perm,
				'callback'            => function () {
					$r = HM_WHM_API::ensure_package();
					return is_wp_error( $r ) ? $r : array( 'ok' => true, 'package' => HM_WHM_API::package_name() );
				},
			)
		);
	}
);

/* =========================================================================
 * Activación y compatibilidad
 * ====================================================================== */

register_activation_hook(
	__FILE__,
	function () {
		if ( ! wp_next_scheduled( 'hm_whm_daily' ) ) {
			// Todos los días a las 9:00 de la zona horaria del sitio.
			$next = ( new DateTimeImmutable( 'tomorrow 09:00', wp_timezone() ) )->getTimestamp();
			wp_schedule_event( $next, 'daily', 'hm_whm_daily' );
		}
		update_option( 'hm_whm_flush', 1 );
	}
);

register_deactivation_hook(
	__FILE__,
	function () {
		wp_clear_scheduled_hook( 'hm_whm_daily' );
		flush_rewrite_rules();
	}
);

add_action(
	'before_woocommerce_init',
	function () {
		if ( class_exists( '\Automattic\WooCommerce\Utilities\FeaturesUtil' ) ) {
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', HM_WHM_FILE, true );
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'cart_checkout_blocks', HM_WHM_FILE, true );
		}
	}
);

<?php
/**
 * Plugin Name: Agente Auth (Claude <-> GitHub)
 * Description: Conecta WordPress con Claude a traves de GitHub. Autentica al agente como el usuario "soporte" mediante token, recibe webhooks de GitHub y sincroniza contenidos (markdown/html) del repo hostingmexico-contents como posts y paginas.
 * Version: 1.0.0
 * Author: hostingmexico.org
 *
 * Instalacion: copiar este archivo a wp-content/mu-plugins/agente-auth.php
 * y definir las constantes de configuracion en wp-config.php (ver README del repo).
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Agente_Auth {

	const NS = 'agente/v1';

	/* ------------------------------------------------------------------
	 * Configuracion (constantes en wp-config.php, con valores por defecto)
	 * ---------------------------------------------------------------- */

	public static function cfg( $key, $default = '' ) {
		$const = 'AGENTE_' . strtoupper( $key );
		return defined( $const ) ? constant( $const ) : $default;
	}

	public static function user_login() {
		return self::cfg( 'wp_user', 'soporte' );
	}

	public static function repo() {
		return self::cfg( 'github_repo', 'fernandomtzamx/hostingmexico-contents' );
	}

	public static function branch() {
		return self::cfg( 'github_branch', 'main' );
	}

	/* ------------------------------------------------------------------
	 * Bootstrap
	 * ---------------------------------------------------------------- */

	public static function init() {
		add_filter( 'determine_current_user', array( __CLASS__, 'auth_bearer' ), 20 );
		add_filter( 'rest_authentication_errors', array( __CLASS__, 'auth_errors' ), 20 );
		add_action( 'rest_api_init', array( __CLASS__, 'routes' ) );
		add_action( 'admin_notices', array( __CLASS__, 'admin_notices' ) );
	}

	/* ------------------------------------------------------------------
	 * Autenticacion por Bearer token -> usuario "soporte"
	 * Permite a Claude usar toda la REST API (/wp-json/wp/v2/...) como soporte.
	 * ---------------------------------------------------------------- */

	private static $auth_error = null;

	private static function bearer_token() {
		$header = '';
		if ( isset( $_SERVER['HTTP_AUTHORIZATION'] ) ) {
			$header = $_SERVER['HTTP_AUTHORIZATION'];
		} elseif ( isset( $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ) ) {
			$header = $_SERVER['REDIRECT_HTTP_AUTHORIZATION'];
		} elseif ( isset( $_SERVER['HTTP_X_AGENTE_TOKEN'] ) ) {
			return trim( $_SERVER['HTTP_X_AGENTE_TOKEN'] );
		}
		if ( stripos( $header, 'Bearer ' ) === 0 ) {
			return trim( substr( $header, 7 ) );
		}
		return '';
	}

	public static function auth_bearer( $user_id ) {
		if ( $user_id ) {
			return $user_id;
		}
		$token    = self::bearer_token();
		$expected = self::cfg( 'auth_token' );
		if ( '' === $token || '' === $expected ) {
			return $user_id;
		}
		if ( ! hash_equals( $expected, $token ) ) {
			self::$auth_error = new WP_Error( 'agente_bad_token', 'Token invalido.', array( 'status' => 401 ) );
			return $user_id;
		}
		$user = get_user_by( 'login', self::user_login() );
		if ( ! $user ) {
			self::$auth_error = new WP_Error( 'agente_no_user', 'El usuario ' . self::user_login() . ' no existe.', array( 'status' => 403 ) );
			return $user_id;
		}
		return (int) $user->ID;
	}

	public static function auth_errors( $result ) {
		if ( ! empty( $result ) ) {
			return $result;
		}
		return self::$auth_error ? self::$auth_error : $result;
	}

	/* ------------------------------------------------------------------
	 * Rutas REST
	 * ---------------------------------------------------------------- */

	public static function routes() {
		register_rest_route( self::NS, '/ping', array(
			'methods'             => 'GET',
			'callback'            => array( __CLASS__, 'route_ping' ),
			'permission_callback' => array( __CLASS__, 'can_sync' ),
		) );
		register_rest_route( self::NS, '/sync', array(
			'methods'             => 'POST',
			'callback'            => array( __CLASS__, 'route_sync' ),
			'permission_callback' => array( __CLASS__, 'can_sync' ),
			'args'                => array(
				'path'   => array( 'type' => 'string', 'required' => false ),
				'status' => array( 'type' => 'string', 'required' => false ),
			),
		) );
		register_rest_route( self::NS, '/webhook', array(
			'methods'             => 'POST',
			'callback'            => array( __CLASS__, 'route_webhook' ),
			'permission_callback' => '__return_true', // se valida con la firma HMAC de GitHub
		) );
	}

	public static function can_sync() {
		$user = wp_get_current_user();
		return $user && $user->ID && $user->user_login === self::user_login() && current_user_can( 'edit_posts' );
	}

	public static function route_ping() {
		return array(
			'ok'     => true,
			'user'   => wp_get_current_user()->user_login,
			'repo'   => self::repo(),
			'branch' => self::branch(),
			'site'   => home_url(),
		);
	}

	public static function route_sync( WP_REST_Request $req ) {
		$path   = $req->get_param( 'path' ) ? sanitize_text_field( $req->get_param( 'path' ) ) : '';
		$status = $req->get_param( 'status' ) ? sanitize_key( $req->get_param( 'status' ) ) : '';
		$result = $path ? self::sync_file( $path, $status ) : self::sync_all( $status );
		return rest_ensure_response( $result );
	}

	public static function route_webhook( WP_REST_Request $req ) {
		$secret = self::cfg( 'github_secret' );
		if ( '' === $secret ) {
			return new WP_Error( 'agente_no_secret', 'AGENTE_GITHUB_SECRET no esta definido.', array( 'status' => 500 ) );
		}
		$sig  = $req->get_header( 'x-hub-signature-256' );
		$body = $req->get_body();
		$calc = 'sha256=' . hash_hmac( 'sha256', $body, $secret );
		if ( ! $sig || ! hash_equals( $calc, $sig ) ) {
			return new WP_Error( 'agente_bad_signature', 'Firma invalida.', array( 'status' => 401 ) );
		}
		$event = $req->get_header( 'x-github-event' );
		if ( 'ping' === $event ) {
			return array( 'ok' => true, 'event' => 'ping' );
		}
		if ( 'push' !== $event ) {
			return array( 'ok' => true, 'ignored' => $event );
		}
		$payload = json_decode( $body, true );
		$ref     = isset( $payload['ref'] ) ? $payload['ref'] : '';
		if ( 'refs/heads/' . self::branch() !== $ref ) {
			return array( 'ok' => true, 'ignored' => 'branch ' . $ref );
		}

		// El webhook se ejecuta sin sesion: actuamos como el usuario soporte.
		$user = get_user_by( 'login', self::user_login() );
		if ( ! $user ) {
			return new WP_Error( 'agente_no_user', 'El usuario ' . self::user_login() . ' no existe.', array( 'status' => 500 ) );
		}
		wp_set_current_user( $user->ID );

		$changed = array();
		$removed = array();
		foreach ( (array) $payload['commits'] as $c ) {
			foreach ( array( 'added', 'modified' ) as $k ) {
				foreach ( (array) $c[ $k ] as $f ) {
					$changed[ $f ] = true;
				}
			}
			foreach ( (array) $c['removed'] as $f ) {
				$removed[ $f ] = true;
				unset( $changed[ $f ] );
			}
		}

		$results = array();
		foreach ( array_keys( $changed ) as $f ) {
			if ( self::is_content_path( $f ) ) {
				$results[ $f ] = self::sync_file( $f );
			}
		}
		foreach ( array_keys( $removed ) as $f ) {
			if ( self::is_content_path( $f ) ) {
				$results[ $f ] = self::unpublish_by_source( $f );
			}
		}
		return array( 'ok' => true, 'results' => $results );
	}

	/* ------------------------------------------------------------------
	 * GitHub
	 * ---------------------------------------------------------------- */

	private static function gh_headers() {
		$h = array(
			'Accept'     => 'application/vnd.github+json',
			'User-Agent' => 'agente-auth/1.0 (' . home_url() . ')',
		);
		$t = self::cfg( 'github_token' );
		if ( $t ) {
			$h['Authorization'] = 'Bearer ' . $t;
		}
		return $h;
	}

	private static function gh_get( $url, $raw = false ) {
		$headers = self::gh_headers();
		if ( $raw ) {
			$headers['Accept'] = 'application/vnd.github.raw';
		}
		$r = wp_remote_get( $url, array( 'headers' => $headers, 'timeout' => 20 ) );
		if ( is_wp_error( $r ) ) {
			return $r;
		}
		$code = wp_remote_retrieve_response_code( $r );
		if ( $code < 200 || $code >= 300 ) {
			return new WP_Error( 'agente_github_http', 'GitHub respondio ' . $code . ' para ' . $url );
		}
		$body = wp_remote_retrieve_body( $r );
		return $raw ? $body : json_decode( $body, true );
	}

	public static function fetch_raw( $path ) {
		$url = sprintf( 'https://api.github.com/repos/%s/contents/%s?ref=%s', self::repo(), rawurlencode( ltrim( $path, '/' ) ), rawurlencode( self::branch() ) );
		$url = str_replace( '%2F', '/', $url );
		return self::gh_get( $url, true );
	}

	public static function list_content_files() {
		$url  = sprintf( 'https://api.github.com/repos/%s/git/trees/%s?recursive=1', self::repo(), rawurlencode( self::branch() ) );
		$tree = self::gh_get( $url );
		if ( is_wp_error( $tree ) ) {
			return $tree;
		}
		$files = array();
		foreach ( (array) $tree['tree'] as $node ) {
			if ( 'blob' === $node['type'] && self::is_content_path( $node['path'] ) ) {
				$files[] = $node['path'];
			}
		}
		return $files;
	}

	public static function is_content_path( $path ) {
		return (bool) preg_match( '#^content/(posts|pages)/.+\.(md|markdown|html)$#i', $path );
	}

	/* ------------------------------------------------------------------
	 * Sincronizacion
	 * ---------------------------------------------------------------- */

	public static function sync_all( $force_status = '' ) {
		$files = self::list_content_files();
		if ( is_wp_error( $files ) ) {
			return $files;
		}
		$out = array();
		foreach ( $files as $f ) {
			$out[ $f ] = self::sync_file( $f, $force_status );
		}
		return array( 'ok' => true, 'count' => count( $out ), 'results' => $out );
	}

	public static function sync_file( $path, $force_status = '' ) {
		$raw = self::fetch_raw( $path );
		if ( is_wp_error( $raw ) ) {
			return array( 'error' => $raw->get_error_message() );
		}
		list( $meta, $body ) = self::parse_front_matter( $raw );

		$type = ( false !== stripos( $path, 'content/pages/' ) ) ? 'page' : 'post';
		if ( ! empty( $meta['type'] ) && in_array( $meta['type'], array( 'post', 'page' ), true ) ) {
			$type = $meta['type'];
		}
		$slug = ! empty( $meta['slug'] ) ? sanitize_title( $meta['slug'] ) : sanitize_title( pathinfo( $path, PATHINFO_FILENAME ) );
		$title = ! empty( $meta['title'] ) ? $meta['title'] : self::title_from_slug( $slug );

		$is_html = (bool) preg_match( '/\.html$/i', $path );
		$content = $is_html ? $body : self::markdown_to_html( $body );

		$status = $force_status ? $force_status : ( ! empty( $meta['status'] ) ? sanitize_key( $meta['status'] ) : 'draft' );
		if ( ! in_array( $status, array( 'draft', 'publish', 'pending', 'private' ), true ) ) {
			$status = 'draft';
		}

		$user = get_user_by( 'login', self::user_login() );
		$existing = self::find_by_source( $path );
		if ( ! $existing ) {
			$existing = get_page_by_path( $slug, OBJECT, $type );
		}

		$data = array(
			'post_type'    => $type,
			'post_title'   => wp_strip_all_tags( $title ),
			'post_name'    => $slug,
			'post_content' => $content,
			'post_status'  => $status,
			'post_author'  => $user ? $user->ID : 0,
		);
		if ( ! empty( $meta['excerpt'] ) ) {
			$data['post_excerpt'] = $meta['excerpt'];
		}
		if ( ! empty( $meta['date'] ) ) {
			$ts = strtotime( $meta['date'] );
			if ( $ts ) {
				$data['post_date'] = wp_date( 'Y-m-d H:i:s', $ts );
			}
		}
		if ( ! empty( $meta['parent'] ) && 'page' === $type ) {
			$parent = get_page_by_path( sanitize_title( $meta['parent'] ), OBJECT, 'page' );
			if ( $parent ) {
				$data['post_parent'] = $parent->ID;
			}
		}

		if ( $existing ) {
			$data['ID'] = $existing->ID;
			$id = wp_update_post( wp_slash( $data ), true );
		} else {
			$id = wp_insert_post( wp_slash( $data ), true );
		}
		if ( is_wp_error( $id ) ) {
			return array( 'error' => $id->get_error_message() );
		}

		update_post_meta( $id, '_agente_source', $path );
		update_post_meta( $id, '_agente_synced', current_time( 'mysql' ) );

		if ( 'post' === $type ) {
			if ( ! empty( $meta['categories'] ) ) {
				wp_set_object_terms( $id, self::to_list( $meta['categories'] ), 'category' );
			}
			if ( ! empty( $meta['tags'] ) ) {
				wp_set_object_terms( $id, self::to_list( $meta['tags'] ), 'post_tag' );
			}
		}
		foreach ( (array) ( isset( $meta['meta'] ) ? $meta['meta'] : array() ) as $k => $v ) {
			update_post_meta( $id, sanitize_key( $k ), $v );
		}
		if ( ! empty( $meta['featured_image'] ) ) {
			self::set_featured_image( $id, $meta['featured_image'] );
		}

		return array(
			'ok'     => true,
			'id'     => $id,
			'type'   => $type,
			'status' => $status,
			'slug'   => $slug,
			'link'   => get_permalink( $id ),
			'edit'   => get_edit_post_link( $id, 'raw' ),
		);
	}

	public static function unpublish_by_source( $path ) {
		$p = self::find_by_source( $path );
		if ( ! $p ) {
			return array( 'ok' => true, 'skipped' => 'no existe' );
		}
		wp_update_post( array( 'ID' => $p->ID, 'post_status' => 'draft' ) );
		return array( 'ok' => true, 'id' => $p->ID, 'status' => 'draft' );
	}

	private static function find_by_source( $path ) {
		$q = get_posts( array(
			'post_type'      => array( 'post', 'page' ),
			'post_status'    => 'any',
			'posts_per_page' => 1,
			'meta_key'       => '_agente_source',
			'meta_value'     => $path,
			'fields'         => 'all',
		) );
		return $q ? $q[0] : null;
	}

	private static function set_featured_image( $post_id, $url ) {
		if ( ! function_exists( 'media_sideload_image' ) ) {
			require_once ABSPATH . 'wp-admin/includes/media.php';
			require_once ABSPATH . 'wp-admin/includes/file.php';
			require_once ABSPATH . 'wp-admin/includes/image.php';
		}
		$current = get_post_meta( $post_id, '_agente_featured_url', true );
		if ( $current === $url && has_post_thumbnail( $post_id ) ) {
			return;
		}
		$att = media_sideload_image( esc_url_raw( $url ), $post_id, null, 'id' );
		if ( ! is_wp_error( $att ) ) {
			set_post_thumbnail( $post_id, $att );
			update_post_meta( $post_id, '_agente_featured_url', $url );
		}
	}

	/* ------------------------------------------------------------------
	 * Front matter (YAML simple: clave: valor, listas [a, b] o "- item")
	 * ---------------------------------------------------------------- */

	public static function parse_front_matter( $raw ) {
		$raw = str_replace( "\r\n", "\n", $raw );
		if ( ! preg_match( '/^---\n(.*?)\n---\n?(.*)$/s', $raw, $m ) ) {
			return array( array(), $raw );
		}
		$meta = array();
		$lines = explode( "\n", $m[1] );
		$key = null;
		$sub = null;
		foreach ( $lines as $line ) {
			if ( preg_match( '/^\s*-\s+(.*)$/', $line, $lm ) && $key ) {
				$target = $sub ? $key . '.' . $sub : $key;
				$meta[ $target ][] = self::yaml_scalar( $lm[1] );
				continue;
			}
			if ( preg_match( '/^(\s*)([A-Za-z0-9_\-]+):\s*(.*)$/', $line, $km ) ) {
				$indent = strlen( $km[1] );
				$k = $km[2];
				$v = trim( $km[3] );
				if ( $indent > 0 && $key ) {
					// subclave (ej. meta: { k: v })
					$meta[ $key ][ $k ] = self::yaml_scalar( $v );
					$sub = null;
					continue;
				}
				$key = $k;
				$sub = null;
				if ( '' === $v ) {
					$meta[ $key ] = array();
				} elseif ( preg_match( '/^\[(.*)\]$/', $v, $am ) ) {
					$meta[ $key ] = array_map( array( __CLASS__, 'yaml_scalar' ), array_map( 'trim', explode( ',', $am[1] ) ) );
				} else {
					$meta[ $key ] = self::yaml_scalar( $v );
				}
			}
		}
		return array( $meta, ltrim( $m[2], "\n" ) );
	}

	private static function yaml_scalar( $v ) {
		$v = trim( $v );
		if ( preg_match( '/^"(.*)"$/s', $v, $q ) || preg_match( "/^'(.*)'$/s", $v, $q ) ) {
			return $q[1];
		}
		if ( 'true' === $v ) { return true; }
		if ( 'false' === $v ) { return false; }
		return $v;
	}

	private static function to_list( $v ) {
		if ( is_array( $v ) ) {
			return array_values( array_filter( array_map( 'trim', $v ) ) );
		}
		return array_values( array_filter( array_map( 'trim', explode( ',', (string) $v ) ) ) );
	}

	private static function title_from_slug( $slug ) {
		return ucfirst( str_replace( '-', ' ', $slug ) );
	}

	/* ------------------------------------------------------------------
	 * Markdown -> HTML (bloques de Gutenberg)
	 * Soporta: encabezados, parrafos, listas, citas, codigo, imagenes,
	 * enlaces, negrita, cursiva, codigo en linea, separadores y HTML crudo.
	 * ---------------------------------------------------------------- */

	public static function markdown_to_html( $md ) {
		$md    = str_replace( "\r\n", "\n", $md );
		$lines = explode( "\n", $md );
		$out   = array();
		$i     = 0;
		$n     = count( $lines );

		while ( $i < $n ) {
			$line = $lines[ $i ];

			if ( '' === trim( $line ) ) { $i++; continue; }

			// bloque de codigo
			if ( preg_match( '/^```(\w*)/', $line, $cm ) ) {
				$code = array();
				$i++;
				while ( $i < $n && ! preg_match( '/^```/', $lines[ $i ] ) ) { $code[] = $lines[ $i ]; $i++; }
				$i++;
				$out[] = "<!-- wp:code -->\n<pre class=\"wp-block-code\"><code>" . esc_html( implode( "\n", $code ) ) . "</code></pre>\n<!-- /wp:code -->";
				continue;
			}

			// HTML crudo (bloque que empieza con etiqueta)
			if ( preg_match( '/^<(div|section|table|figure|iframe|video|audio|form|details|script|style|ul|ol|p|h[1-6]|blockquote|pre|hr)\b/i', $line ) ) {
				$html = array();
				while ( $i < $n && '' !== trim( $lines[ $i ] ) ) { $html[] = $lines[ $i ]; $i++; }
				$out[] = "<!-- wp:html -->\n" . implode( "\n", $html ) . "\n<!-- /wp:html -->";
				continue;
			}

			// encabezados
			if ( preg_match( '/^(#{1,6})\s+(.*)$/', $line, $hm ) ) {
				$lvl = strlen( $hm[1] );
				$out[] = sprintf( "<!-- wp:heading {\"level\":%d} -->\n<h%d class=\"wp-block-heading\">%s</h%d>\n<!-- /wp:heading -->", $lvl, $lvl, self::inline( $hm[2] ), $lvl );
				$i++;
				continue;
			}

			// separador
			if ( preg_match( '/^(-{3,}|\*{3,})\s*$/', $line ) ) {
				$out[] = "<!-- wp:separator -->\n<hr class=\"wp-block-separator has-alpha-channel-opacity\"/>\n<!-- /wp:separator -->";
				$i++;
				continue;
			}

			// imagen sola en una linea
			if ( preg_match( '/^!\[(.*?)\]\((\S+?)(?:\s+"(.*?)")?\)\s*$/', $line, $im ) ) {
				$cap = ! empty( $im[3] ) ? '<figcaption class="wp-element-caption">' . esc_html( $im[3] ) . '</figcaption>' : '';
				$out[] = "<!-- wp:image -->\n<figure class=\"wp-block-image\"><img src=\"" . esc_url( $im[2] ) . "\" alt=\"" . esc_attr( $im[1] ) . "\"/>" . $cap . "</figure>\n<!-- /wp:image -->";
				$i++;
				continue;
			}

			// cita
			if ( preg_match( '/^>\s?/', $line ) ) {
				$q = array();
				while ( $i < $n && preg_match( '/^>\s?(.*)$/', $lines[ $i ], $qm ) ) { $q[] = $qm[1]; $i++; }
				$out[] = "<!-- wp:quote -->\n<blockquote class=\"wp-block-quote\"><p>" . self::inline( implode( ' ', $q ) ) . "</p></blockquote>\n<!-- /wp:quote -->";
				continue;
			}

			// listas
			if ( preg_match( '/^\s*([-*+]|\d+\.)\s+/', $line ) ) {
				$ordered = (bool) preg_match( '/^\s*\d+\.\s+/', $line );
				$items   = array();
				while ( $i < $n && preg_match( '/^\s*(?:[-*+]|\d+\.)\s+(.*)$/', $lines[ $i ], $lm ) ) { $items[] = '<li>' . self::inline( $lm[1] ) . '</li>'; $i++; }
				$tag = $ordered ? 'ol' : 'ul';
				$attr = $ordered ? ' {"ordered":true}' : '';
				$out[] = "<!-- wp:list$attr -->\n<$tag class=\"wp-block-list\">" . implode( '', $items ) . "</$tag>\n<!-- /wp:list -->";
				continue;
			}

			// parrafo
			$p = array();
			while ( $i < $n && '' !== trim( $lines[ $i ] ) && ! preg_match( '/^(#{1,6}\s|```|>|\s*(?:[-*+]|\d+\.)\s+|!\[|-{3,}\s*$)/', $lines[ $i ] ) ) { $p[] = $lines[ $i ]; $i++; }
			if ( ! $p ) { $p[] = $line; $i++; }
			$out[] = "<!-- wp:paragraph -->\n<p>" . self::inline( implode( ' ', $p ) ) . "</p>\n<!-- /wp:paragraph -->";
		}

		return implode( "\n\n", $out );
	}

	private static function inline( $t ) {
		$t = esc_html( $t );
		$t = preg_replace( '/`([^`]+)`/', '<code>$1</code>', $t );
		$t = preg_replace( '/!\[(.*?)\]\((\S+?)\)/', '<img src="$2" alt="$1"/>', $t );
		$t = preg_replace( '/\[(.+?)\]\((\S+?)\)/', '<a href="$2">$1</a>', $t );
		$t = preg_replace( '/\*\*(.+?)\*\*/s', '<strong>$1</strong>', $t );
		$t = preg_replace( '/(?<![\w*])\*(?!\s)(.+?)(?<!\s)\*(?![\w*])/s', '<em>$1</em>', $t );
		$t = preg_replace( '/(?<![\w_])_(?!\s)(.+?)(?<!\s)_(?![\w_])/s', '<em>$1</em>', $t );
		$t = preg_replace( '/~~(.+?)~~/s', '<s>$1</s>', $t );
		// esc_html convirtio comillas y & dentro de atributos; restaurar en hrefs
		$t = preg_replace_callback( '/(href|src)="([^"]*)"/', function ( $m ) { return $m[1] . '="' . esc_url( html_entity_decode( $m[2] ) ) . '"'; }, $t );
		return $t;
	}

	/* ------------------------------------------------------------------
	 * Avisos de configuracion en wp-admin
	 * ---------------------------------------------------------------- */

	public static function admin_notices() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$faltan = array();
		if ( ! self::cfg( 'auth_token' ) ) { $faltan[] = 'AGENTE_AUTH_TOKEN'; }
		if ( ! self::cfg( 'github_secret' ) ) { $faltan[] = 'AGENTE_GITHUB_SECRET'; }
		if ( ! get_user_by( 'login', self::user_login() ) ) { $faltan[] = 'usuario "' . self::user_login() . '"'; }
		if ( $faltan ) {
			echo '<div class="notice notice-warning"><p><strong>Agente Auth:</strong> falta configurar ' . esc_html( implode( ', ', $faltan ) ) . '. Revisa el README de hostingmexico-contents.</p></div>';
		}
	}
}

Agente_Auth::init();

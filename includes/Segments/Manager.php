<?php

namespace ZeusWeb\Multishop\Segments;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Manager {
	public const QUERY_VAR = 'zw_ms_segment';
	public const PATH_VAR  = 'zw_ms_path';
	public const COOKIE    = 'zw_ms_segment';

	public static function init(): void {
		add_filter( 'query_vars', [ __CLASS__, 'register_query_vars' ] );
		add_action( 'init', [ __CLASS__, 'register_rewrites' ] );
		add_filter( 'request', [ __CLASS__, 'rewrite_request_path' ] );
		add_action( 'template_redirect', [ __CLASS__, 'handle_segment_entry' ], 1 );
		add_action( 'init', [ __CLASS__, 'maybe_set_segment_from_param' ], 1 );
	}

	public static function register_query_vars( array $vars ): array {
		$vars[] = self::QUERY_VAR;
		$vars[] = self::PATH_VAR;
		return $vars;
	}

	public static function register_rewrites(): void {
		// Consumer: /lakossagi/... (base rule only if a page with this slug doesn't exist)
		if ( ! get_page_by_path( 'lakossagi' ) ) {
			add_rewrite_rule( '^lakossagi/?$', 'index.php?' . self::QUERY_VAR . '=consumer', 'top' );
		}
		add_rewrite_rule( '^lakossagi/(.*)$', 'index.php?' . self::QUERY_VAR . '=consumer&' . self::PATH_VAR . '=$matches[1]', 'top' );
		// Business: /uzleti/... (base rule only if a page with this slug doesn't exist)
		if ( ! get_page_by_path( 'uzleti' ) ) {
			add_rewrite_rule( '^uzleti/?$', 'index.php?' . self::QUERY_VAR . '=business', 'top' );
		}
		add_rewrite_rule( '^uzleti/(.*)$', 'index.php?' . self::QUERY_VAR . '=business&' . self::PATH_VAR . '=$matches[1]', 'top' );
	}

	public static function rewrite_request_path( array $request ): array {
		if ( isset( $request[ self::PATH_VAR ] ) && is_string( $request[ self::PATH_VAR ] ) ) {
			$path = trim( (string) $request[ self::PATH_VAR ], '/' );
			unset( $request[ self::PATH_VAR ] );
			// Try to resolve a single post/page/product by path. If found, set 'p' to avoid 404s.
			$maybe_id = self::resolve_path_to_post_id( $path );
			if ( $maybe_id ) {
				$post_type = get_post_type( $maybe_id ) ?: 'post';
				$request['p'] = $maybe_id;
				$request['post_type'] = $post_type;
				unset( $request['pagename'] );
			} else {
				// WooCommerce product fallback by slug (last segment) when using category-based permalinks
				$segments = array_values( array_filter( explode( '/', $path ) ) );
				$last = $segments ? end( $segments ) : '';
				if ( $last ) {
					$request['name'] = $last;
					$request['post_type'] = 'product';
					unset( $request['pagename'] );
				} else {
					// Fallback minimal: set pagename so regular pages still work
					$request = self::parse_path_into_request( $path, $request );
				}
			}
		}

		// If no PATH_VAR was set (i.e., base /lakossagi or /uzleti), do not alter request; let WP load the page content.
		return $request;
	}

	private static function parse_path_into_request( string $path, array $request ): array {
		// Minimal approach: set 'pagename' for pretty permalinks, WordPress will handle nested pages.
		// For archives/singles/taxonomies, WordPress will still resolve via its own rules using the path.
		$request['pagename'] = $path;
		return $request;
	}

	private static function resolve_path_to_post_id( string $path ): int {
		$home = home_url( '/' . ltrim( $path, '/' ) . '/' );
		$id = url_to_postid( $home );
		return (int) $id;
	}

	public static function handle_segment_entry(): void {
		$current = self::get_current_segment();
		if ( ! $current ) {
			return;
		}
		$previous = isset( $_COOKIE[ self::COOKIE ] ) ? sanitize_text_field( wp_unslash( $_COOKIE[ self::COOKIE ] ) ) : '';
		if ( $previous && $previous !== $current ) {
			if ( function_exists( 'WC' ) && WC()->cart ) {
				WC()->cart->empty_cart();
			}
		}
		// Refresh cookie for 30 days and persist in WooCommerce session if available.
		setcookie( self::COOKIE, $current, time() + 30 * DAY_IN_SECONDS, COOKIEPATH ? COOKIEPATH : '/', COOKIE_DOMAIN, is_ssl(), true );
		if ( function_exists( 'WC' ) && WC()->session ) {
			WC()->session->set( self::COOKIE, $current );
		}

        // Do not alter base pages' content; if user created pages at /lakossagi or /uzleti, let WP render them.
	}

	public static function maybe_set_segment_from_param(): void {
		if ( isset( $_GET['zw_ms_set_segment'] ) ) {
			$seg = sanitize_text_field( wp_unslash( $_GET['zw_ms_set_segment'] ) );
			if ( in_array( $seg, [ 'consumer', 'business' ], true ) ) {
				// Set cookie immediately; cart emptying will occur on next template_redirect in handle_segment_entry
				setcookie( self::COOKIE, $seg, time() + 30 * DAY_IN_SECONDS, COOKIEPATH ? COOKIEPATH : '/', COOKIE_DOMAIN, is_ssl(), true );
				if ( function_exists( 'WC' ) && WC()->session ) {
					WC()->session->set( self::COOKIE, $seg );
				}
			}
		}
	}

	public static function get_current_segment(): string {
		$segment = get_query_var( self::QUERY_VAR );
		if ( $segment === 'consumer' || $segment === 'business' ) {
			return $segment;
		}
		// Prefer persisted selection over path detection
		$from_cookie = isset( $_COOKIE[ self::COOKIE ] ) ? sanitize_text_field( wp_unslash( $_COOKIE[ self::COOKIE ] ) ) : '';
		if ( $from_cookie === 'consumer' || $from_cookie === 'business' ) {
			return $from_cookie;
		}
		if ( function_exists( 'WC' ) && WC()->session ) {
			$from_session = (string) WC()->session->get( self::COOKIE, '' );
			if ( $from_session === 'consumer' || $from_session === 'business' ) {
				return $from_session;
			}
		}
		$from_path = self::detect_segment_from_path();
		if ( $from_path ) {
			return $from_path;
		}
		return '';
	}

	private static function detect_segment_from_path(): string {
		$uri = isset( $_SERVER['REQUEST_URI'] ) ? (string) $_SERVER['REQUEST_URI'] : '';
		$path = strtok( $uri, '?' );
		if ( $path && preg_match( '#/(lakossagi)(/|$)#', $path ) ) {
			return 'consumer';
		}
		if ( $path && preg_match( '#/(uzleti)(/|$)#', $path ) ) {
			return 'business';
		}
		return '';
	}

	public static function is_business(): bool {
		return self::get_current_segment() === 'business';
	}

	public static function is_consumer(): bool {
		return self::get_current_segment() === 'consumer';
	}
}



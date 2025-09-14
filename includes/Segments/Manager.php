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
		
		// Add debug notice for admins
		add_action( 'wp_footer', [ __CLASS__, 'show_debug_notice' ], 9999 );
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
		
		// Get previous segment from cookie
		$previous = isset( $_COOKIE[ self::COOKIE ] ) ? sanitize_text_field( wp_unslash( $_COOKIE[ self::COOKIE ] ) ) : '';
		
		// If segment changed, empty the cart
		if ( $previous && $previous !== $current ) {
			if ( function_exists( 'WC' ) && WC()->cart ) {
				WC()->cart->empty_cart();
			}
		}
		
		// Always update cookie and session to ensure persistence
		self::set_segment_cookie( $current );
		// Update superglobal so downstream hooks see the new value in this request
		$_COOKIE[ self::COOKIE ] = $current;
		
		// Also store in WooCommerce session if available
		if ( function_exists( 'WC' ) && WC()->session ) {
			WC()->session->set( self::COOKIE, $current );
		}
	}

	public static function maybe_set_segment_from_param(): void {
		if ( isset( $_GET['zw_ms_set_segment'] ) ) {
			$seg = sanitize_text_field( wp_unslash( $_GET['zw_ms_set_segment'] ) );
			if ( in_array( $seg, [ 'consumer', 'business' ], true ) ) {
				// Get previous segment for cart clearing
				$previous = isset( $_COOKIE[ self::COOKIE ] ) ? sanitize_text_field( wp_unslash( $_COOKIE[ self::COOKIE ] ) ) : '';
				
				// Set cookie immediately
				self::set_segment_cookie( $seg );
				$_COOKIE[ self::COOKIE ] = $seg; // Update superglobal for immediate use
				
				// Store in WooCommerce session
				if ( function_exists( 'WC' ) && WC()->session ) {
					WC()->session->set( self::COOKIE, $seg );
				}
				
				// Empty cart if segment changed
				if ( $previous && $previous !== $seg ) {
					if ( function_exists( 'WC' ) && WC()->cart ) {
						WC()->cart->empty_cart();
					}
				}
				
				// Redirect to clean URL
				nocache_headers();
				$target = remove_query_arg( [ 'zw_ms_set_segment' ] );
				if ( ! headers_sent() ) {
					wp_safe_redirect( $target );
					exit;
				}
			}
		}
	}

	/**
	 * Robustly set the segment cookie for both COOKIEPATH and SITECOOKIEPATH with modern attributes.
	 */
	private static function set_segment_cookie( string $value ): void {
		$expire = time() + 30 * DAY_IN_SECONDS;
		$paths = [];
		$paths[] = ( defined( 'COOKIEPATH' ) && COOKIEPATH ) ? COOKIEPATH : '/';
		if ( defined( 'SITECOOKIEPATH' ) && SITECOOKIEPATH ) {
			$paths[] = SITECOOKIEPATH;
		}
		$paths = array_unique( $paths );

		foreach ( $paths as $path ) {
			if ( function_exists( 'wc_setcookie' ) ) {
				\wc_setcookie( self::COOKIE, $value, $expire, $path );
				continue;
			}
			if ( PHP_VERSION_ID >= 70300 ) {
				@setcookie( self::COOKIE, $value, [
					'expires'  => $expire,
					'path'     => $path ?: '/',
					'domain'   => defined( 'COOKIE_DOMAIN' ) && COOKIE_DOMAIN ? COOKIE_DOMAIN : '',
					'secure'   => is_ssl(),
					'httponly' => true,
					'samesite' => 'Lax',
				] );
			} else {
				@setcookie( self::COOKIE, $value, $expire, $path ?: '/', defined( 'COOKIE_DOMAIN' ) && COOKIE_DOMAIN ? COOKIE_DOMAIN : '', is_ssl(), true );
			}
		}
	}

	public static function get_current_segment(): string {
		// 1) Explicit URL param always wins
		if ( isset( $_GET['zw_ms_set_segment'] ) ) {
			$seg = sanitize_text_field( wp_unslash( $_GET['zw_ms_set_segment'] ) );
			if ( in_array( $seg, [ 'consumer', 'business' ], true ) ) {
				return $seg;
			}
		}
		
		// 2) Query var from rewrites
		$segment = get_query_var( self::QUERY_VAR );
		if ( $segment === 'consumer' || $segment === 'business' ) {
			return $segment;
		}
		
		// 3) URL path (/lakossagi or /uzleti) should override persistence
		$from_path = self::detect_segment_from_path();
		if ( $from_path ) {
			return $from_path;
		}
		
		// 4) Persisted cookie/session
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
		
		// 5) No segment
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
	
	public static function show_debug_notice(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		
		$segment = self::get_current_segment();
		$cookie = isset( $_COOKIE[ self::COOKIE ] ) ? $_COOKIE[ self::COOKIE ] : 'not set';
		$query_var = get_query_var( self::QUERY_VAR ) ?: 'not set';
		$param = isset( $_GET['zw_ms_set_segment'] ) ? sanitize_text_field( wp_unslash( $_GET['zw_ms_set_segment'] ) ) : 'not set';
		$path_detect = self::detect_segment_from_path() ?: 'none';
		
		echo '<div style="position: fixed; bottom: 10px; right: 10px; background: #333; color: #fff; padding: 10px; z-index: 99999; font-size: 12px; border-radius: 5px;">';
		echo '<strong>Multishop Debug:</strong><br>';
		echo 'Current Segment: <strong>' . ( $segment ?: 'none' ) . '</strong><br>';
		echo 'Cookie: ' . esc_html( $cookie ) . '<br>';
		echo 'Query Var: ' . esc_html( $query_var ) . '<br>';
		echo 'GET Param: ' . esc_html( $param ) . '<br>';
		echo 'Path Detect: ' . esc_html( $path_detect ) . '<br>';
		echo '</div>';
	}
}



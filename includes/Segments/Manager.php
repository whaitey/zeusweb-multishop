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
		// Core functionality
		add_filter( 'query_vars', [ __CLASS__, 'register_query_vars' ] );
		add_action( 'init', [ __CLASS__, 'register_rewrites' ] );
		add_filter( 'request', [ __CLASS__, 'rewrite_request_path' ] );
		
		// Handle segment detection and persistence VERY early
		add_action( 'plugins_loaded', [ __CLASS__, 'detect_and_persist_segment' ], 1 );
		
		// Handle cart clearing on segment switch
		add_action( 'template_redirect', [ __CLASS__, 'maybe_clear_cart' ], 1 );
		
		// Add JavaScript cookie setter as fallback
		add_action( 'wp_head', [ __CLASS__, 'js_cookie_setter' ], 1 );
		
		// Add debug notice for admins
		add_action( 'wp_footer', [ __CLASS__, 'show_debug_notice' ], 9999 );
	}

	public static function register_query_vars( array $vars ): array {
		$vars[] = self::QUERY_VAR;
		$vars[] = self::PATH_VAR;
		return $vars;
	}

	public static function register_rewrites(): void {
		// Consumer: /lakossagi/...
		if ( ! get_page_by_path( 'lakossagi' ) ) {
			add_rewrite_rule( '^lakossagi/?$', 'index.php?' . self::QUERY_VAR . '=consumer', 'top' );
		}
		add_rewrite_rule( '^lakossagi/(.*)$', 'index.php?' . self::QUERY_VAR . '=consumer&' . self::PATH_VAR . '=$matches[1]', 'top' );
		
		// Business: /uzleti/...
		if ( ! get_page_by_path( 'uzleti' ) ) {
			add_rewrite_rule( '^uzleti/?$', 'index.php?' . self::QUERY_VAR . '=business', 'top' );
		}
		add_rewrite_rule( '^uzleti/(.*)$', 'index.php?' . self::QUERY_VAR . '=business&' . self::PATH_VAR . '=$matches[1]', 'top' );
	}

	public static function rewrite_request_path( array $request ): array {
		if ( isset( $request[ self::PATH_VAR ] ) && is_string( $request[ self::PATH_VAR ] ) ) {
			$path = trim( (string) $request[ self::PATH_VAR ], '/' );
			unset( $request[ self::PATH_VAR ] );
			
			// Try to resolve a single post/page/product by path
			$maybe_id = self::resolve_path_to_post_id( $path );
			if ( $maybe_id ) {
				$post_type = get_post_type( $maybe_id ) ?: 'post';
				$request['p'] = $maybe_id;
				$request['post_type'] = $post_type;
				unset( $request['pagename'] );
			} else {
				// WooCommerce product fallback by slug
				$segments = array_values( array_filter( explode( '/', $path ) ) );
				$last = $segments ? end( $segments ) : '';
				if ( $last ) {
					$request['name'] = $last;
					$request['post_type'] = 'product';
					unset( $request['pagename'] );
				} else {
					$request['pagename'] = $path;
				}
			}
		}
		return $request;
	}

	private static function resolve_path_to_post_id( string $path ): int {
		$home = home_url( '/' . ltrim( $path, '/' ) . '/' );
		$id = url_to_postid( $home );
		return (int) $id;
	}

	/**
	 * Detect segment and persist it VERY early in the request
	 */
	public static function detect_and_persist_segment(): void {
		$new_segment = null;
		
		// 1. Check URL parameter (highest priority)
		if ( isset( $_GET['zw_ms_set_segment'] ) ) {
			$seg = sanitize_text_field( wp_unslash( $_GET['zw_ms_set_segment'] ) );
			if ( in_array( $seg, [ 'consumer', 'business' ], true ) ) {
				$new_segment = $seg;
			}
		}
		
		// 2. Check if we're on a segment-specific path
		if ( ! $new_segment ) {
			$uri = isset( $_SERVER['REQUEST_URI'] ) ? (string) $_SERVER['REQUEST_URI'] : '';
			$path = strtok( $uri, '?' );
			if ( $path && preg_match( '#/(lakossagi)(/|$)#', $path ) ) {
				$new_segment = 'consumer';
			} elseif ( $path && preg_match( '#/(uzleti)(/|$)#', $path ) ) {
				$new_segment = 'business';
			}
		}
		
		// If we detected a segment, persist it
		if ( $new_segment ) {
			// Get current cookie value
			$current_cookie = isset( $_COOKIE[ self::COOKIE ] ) ? sanitize_text_field( wp_unslash( $_COOKIE[ self::COOKIE ] ) ) : '';
			
			// Mark if segment changed for cart clearing
			if ( $current_cookie && $current_cookie !== $new_segment ) {
				if ( ! session_id() ) {
					@session_start();
				}
				$_SESSION['_zw_ms_segment_switched'] = true;
			}
			
			// Try to set the cookie via PHP
			self::set_segment_cookie( $new_segment );
			
			// Update superglobal immediately
			$_COOKIE[ self::COOKIE ] = $new_segment;
			
			// Store in WooCommerce session as backup
			if ( function_exists( 'WC' ) && WC()->session ) {
				WC()->session->set( self::COOKIE, $new_segment );
			}
		}
		
		// If URL param was used, redirect to clean URL
		if ( isset( $_GET['zw_ms_set_segment'] ) && $new_segment ) {
			$target = remove_query_arg( [ 'zw_ms_set_segment' ] );
			if ( ! headers_sent() ) {
				wp_safe_redirect( $target );
				exit;
			}
		}
	}

	/**
	 * Clear cart if segment was switched
	 */
	public static function maybe_clear_cart(): void {
		if ( ! session_id() ) {
			@session_start();
		}
		if ( isset( $_SESSION['_zw_ms_segment_switched'] ) && $_SESSION['_zw_ms_segment_switched'] ) {
			if ( function_exists( 'WC' ) && WC()->cart ) {
				WC()->cart->empty_cart();
			}
			unset( $_SESSION['_zw_ms_segment_switched'] );
		}
	}

	/**
	 * Set the segment cookie robustly
	 */
	private static function set_segment_cookie( string $value ): void {
		if ( headers_sent() ) {
			// Can't set cookie via PHP, will use JavaScript fallback
			return;
		}
		
		$expire = time() + 30 * DAY_IN_SECONDS;
		
		// Set cookie for root path to ensure it works everywhere
		if ( PHP_VERSION_ID >= 70300 ) {
			setcookie( self::COOKIE, $value, [
				'expires'  => $expire,
				'path'     => '/',
				'domain'   => '', // Let browser determine
				'secure'   => is_ssl(),
				'httponly' => false, // Allow JS access
				'samesite' => 'Lax',
			] );
		} else {
			setcookie( self::COOKIE, $value, $expire, '/', '', is_ssl(), false );
		}
	}

	/**
	 * JavaScript fallback for setting cookies when headers are already sent
	 */
	public static function js_cookie_setter(): void {
		// Determine what segment should be set based on current context
		$segment_to_set = null;
		
		// Check URL parameter
		if ( isset( $_GET['zw_ms_set_segment'] ) ) {
			$seg = sanitize_text_field( wp_unslash( $_GET['zw_ms_set_segment'] ) );
			if ( in_array( $seg, [ 'consumer', 'business' ], true ) ) {
				$segment_to_set = $seg;
			}
		}
		
		// Check path
		if ( ! $segment_to_set ) {
			$uri = isset( $_SERVER['REQUEST_URI'] ) ? (string) $_SERVER['REQUEST_URI'] : '';
			$path = strtok( $uri, '?' );
			if ( $path && preg_match( '#/(lakossagi)(/|$)#', $path ) ) {
				$segment_to_set = 'consumer';
			} elseif ( $path && preg_match( '#/(uzleti)(/|$)#', $path ) ) {
				$segment_to_set = 'business';
			}
		}
		
		if ( $segment_to_set ) {
			?>
			<script type="text/javascript">
			(function() {
				var segmentToSet = '<?php echo esc_js( $segment_to_set ); ?>';
				var cookieName = '<?php echo esc_js( self::COOKIE ); ?>';
				
				// Get current cookie value
				var currentCookie = '';
				var cookies = document.cookie.split(';');
				for (var i = 0; i < cookies.length; i++) {
					var cookie = cookies[i].trim();
					if (cookie.indexOf(cookieName + '=') === 0) {
						currentCookie = cookie.substring(cookieName.length + 1);
						break;
					}
				}
				
				// Set cookie if different
				if (currentCookie !== segmentToSet) {
					var expires = new Date();
					expires.setTime(expires.getTime() + (30 * 24 * 60 * 60 * 1000)); // 30 days
					document.cookie = cookieName + '=' + segmentToSet + '; expires=' + expires.toUTCString() + '; path=/; SameSite=Lax';
					
					// If we just set the cookie, reload the page to apply it
					if (currentCookie && currentCookie !== segmentToSet) {
						// Segment changed, reload to apply
						window.location.reload();
					}
				}
			})();
			</script>
			<?php
		}
	}

	/**
	 * Get the current segment
	 */
	public static function get_current_segment(): string {
		// 1. Check if we're forcing a segment via URL
		if ( isset( $_GET['zw_ms_set_segment'] ) ) {
			$seg = sanitize_text_field( wp_unslash( $_GET['zw_ms_set_segment'] ) );
			if ( in_array( $seg, [ 'consumer', 'business' ], true ) ) {
				return $seg;
			}
		}
		
		// 2. Check path
		$uri = isset( $_SERVER['REQUEST_URI'] ) ? (string) $_SERVER['REQUEST_URI'] : '';
		$path = strtok( $uri, '?' );
		if ( $path && preg_match( '#/(lakossagi)(/|$)#', $path ) ) {
			return 'consumer';
		} elseif ( $path && preg_match( '#/(uzleti)(/|$)#', $path ) ) {
			return 'business';
		}
		
		// 3. Check cookie
		$from_cookie = isset( $_COOKIE[ self::COOKIE ] ) ? sanitize_text_field( wp_unslash( $_COOKIE[ self::COOKIE ] ) ) : '';
		if ( in_array( $from_cookie, [ 'consumer', 'business' ], true ) ) {
			return $from_cookie;
		}
		
		// 4. Check WooCommerce session
		if ( function_exists( 'WC' ) && WC()->session ) {
			$from_session = (string) WC()->session->get( self::COOKIE, '' );
			if ( in_array( $from_session, [ 'consumer', 'business' ], true ) ) {
				return $from_session;
			}
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
		
		$uri = isset( $_SERVER['REQUEST_URI'] ) ? (string) $_SERVER['REQUEST_URI'] : '';
		$path = strtok( $uri, '?' );
		$path_detect = 'none';
		if ( $path && preg_match( '#/(lakossagi)(/|$)#', $path ) ) {
			$path_detect = 'consumer';
		} elseif ( $path && preg_match( '#/(uzleti)(/|$)#', $path ) ) {
			$path_detect = 'business';
		}
		
		// Check WC session
		$session_val = 'not set';
		if ( function_exists( 'WC' ) && WC()->session ) {
			$session_val = (string) WC()->session->get( self::COOKIE, 'not set' );
		}
		
		echo '<div style="position: fixed; bottom: 10px; right: 10px; background: #333; color: #fff; padding: 10px; z-index: 99999; font-size: 12px; border-radius: 5px;">';
		echo '<strong>Multishop Debug:</strong><br>';
		echo 'Current Segment: <strong>' . ( $segment ?: 'none' ) . '</strong><br>';
		echo 'Cookie: ' . esc_html( $cookie ) . '<br>';
		echo 'WC Session: ' . esc_html( $session_val ) . '<br>';
		echo 'Query Var: ' . esc_html( $query_var ) . '<br>';
		echo 'GET Param: ' . esc_html( $param ) . '<br>';
		echo 'Path Detect: ' . esc_html( $path_detect ) . '<br>';
		echo 'Headers Sent: ' . ( headers_sent() ? 'yes' : 'no' ) . '<br>';
		echo '</div>';
	}
}
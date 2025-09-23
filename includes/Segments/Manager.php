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
		// In multi-domain rework, segment is determined by site role; disable path/cookie rewrites
		add_action( 'plugins_loaded', [ __CLASS__, 'detect_and_persist_segment' ], 1 );
	}

	public static function register_query_vars( array $vars ): array {
		$vars[] = self::QUERY_VAR;
		$vars[] = self::PATH_VAR;
		return $vars;
	}

	public static function register_rewrites(): void { /* no-op in multi-domain mode */ }

	public static function rewrite_request_path( array $request ): array { return $request; }

	private static function resolve_path_to_post_id( string $path ): int {
		$home = home_url( '/' . ltrim( $path, '/' ) . '/' );
		$id = url_to_postid( $home );
		return (int) $id;
	}

	/**
	 * Detect segment and persist it VERY early in the request
	 */
	public static function detect_and_persist_segment(): void {
		// Persist derived segment (based on site role) to WC session/cookie for legacy consumers of this value.
		$seg = self::get_current_segment();
		if ( ! $seg ) { return; }
		// Update cookie (best effort)
		self::set_segment_cookie( $seg );
		$_COOKIE[ self::COOKIE ] = $seg;
		// Store in WooCommerce session as backup
		if ( function_exists( 'WC' ) && WC()->session ) {
			WC()->session->set( self::COOKIE, $seg );
		}
	}

	/**
	 * Clear cart if segment was switched
	 */
	public static function maybe_clear_cart(): void { /* no-op; no segment switching */ }

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
	 * Keep WooCommerce session in sync with current signals so it persists across navigation.
	 */
	public static function sync_session_from_signals(): void {
		if ( ! function_exists( 'WC' ) || ! WC()->session ) { return; }
		$desired = null;
		// URL param
		if ( isset( $_GET['zw_ms_set_segment'] ) ) {
			$seg = sanitize_text_field( wp_unslash( $_GET['zw_ms_set_segment'] ) );
			if ( in_array( $seg, [ 'consumer', 'business' ], true ) ) { $desired = $seg; }
		}
		// Path
		if ( ! $desired ) {
			$uri = isset( $_SERVER['REQUEST_URI'] ) ? (string) $_SERVER['REQUEST_URI'] : '';
			$path = strtok( $uri, '?' );
			if ( $path && preg_match( '#/(lakossagi)(/|$)#', $path ) ) { $desired = 'consumer'; }
			elseif ( $path && preg_match( '#/(uzleti)(/|$)#', $path ) ) { $desired = 'business'; }
		}
		$session_seg = (string) WC()->session->get( self::COOKIE, '' );
		if ( ! $desired ) {
			// fallback to existing session or cookie
			if ( $session_seg ) { $desired = $session_seg; }
			elseif ( isset( $_COOKIE[ self::COOKIE ] ) ) {
				$c = sanitize_text_field( wp_unslash( $_COOKIE[ self::COOKIE ] ) );
				if ( in_array( $c, [ 'consumer', 'business' ], true ) ) { $desired = $c; }
			}
		}
		if ( $desired && $desired !== $session_seg ) {
			WC()->session->set( self::COOKIE, $desired );
			self::set_segment_cookie( $desired );
			$_COOKIE[ self::COOKIE ] = $desired;
			if ( $session_seg ) {
				WC()->session->set( '_zw_ms_segment_switched', 1 );
			}
		}
	}

	/**
	 * JavaScript fallback for setting cookies when headers are already sent
	 */
	public static function js_cookie_setter(): void { /* no-op */ }

	/**
	 * Get the current segment - SIMPLIFIED LOGIC
	 */
	public static function get_current_segment(): string {
		// In multi-domain mode: primary => consumer; secondary => business
		$mode = get_option( 'zw_ms_mode', 'primary' );
		return $mode === 'primary' ? 'consumer' : 'business';
	}

	public static function add_segment_to_url( $url ) { return $url; }

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
		
		// Check JavaScript readable cookie
		echo '<script>console.log("Cookie from JS:", document.cookie);</script>';
		
		echo '<div style="position: fixed; bottom: 10px; right: 10px; background: #333; color: #fff; padding: 10px; z-index: 99999; font-size: 12px; border-radius: 5px;">';
		echo '<strong>Multishop Debug:</strong><br>';
		echo 'Current Segment: <strong>' . ( $segment ?: 'none' ) . '</strong><br>';
		echo 'PHP Cookie: ' . esc_html( $cookie ) . '<br>';
		echo 'WC Session: ' . esc_html( $session_val ) . '<br>';
		echo 'Query Var: ' . esc_html( $query_var ) . '<br>';
		echo 'GET Param: ' . esc_html( $param ) . '<br>';
		echo 'Path Detect: ' . esc_html( $path_detect ) . '<br>';
		echo 'Headers Sent: ' . ( headers_sent() ? 'yes' : 'no' ) . '<br>';
		echo '<small>Check console for JS cookie</small>';
		echo '</div>';
	}
}
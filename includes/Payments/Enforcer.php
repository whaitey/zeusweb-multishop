<?php

namespace ZeusWeb\Multishop\Payments;

use ZeusWeb\Multishop\Segments\Manager as SegmentManager;
use ZeusWeb\Multishop\Logger\Logger;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Enforcer {
	public static function init(): void {
		add_filter( 'woocommerce_available_payment_gateways', [ __CLASS__, 'filter_gateways' ] );
	}

	public static function filter_gateways( $gateways ) {
		try {
			if ( get_option( 'zw_ms_mode', 'primary' ) !== 'secondary' ) { return $gateways; }
			$segment = SegmentManager::is_business() ? 'business' : 'consumer';
			$allowed = self::get_allowed_gateways_for_segment( $segment );
			// If fetch failed (null), do not block checkout; keep original list
			if ( $allowed === null ) {
				Logger::instance()->log( 'warning', 'payments-config unavailable; not enforcing', [ 'segment' => $segment ] );
				return $gateways;
			}
			// If no gateways are allowed (valid empty mapping), hide all.
			if ( empty( $allowed ) ) {
				Logger::instance()->log( 'info', 'No gateways allowed for segment; hiding all', [ 'segment' => $segment ] );
				return [];
			}

			$allowed_map = array_fill_keys( $allowed, true );
			$stripe_allowed = isset( $allowed_map['stripe'] );
			$has_base_stripe = isset( $gateways['stripe'] );

			foreach ( $gateways as $id => $g ) {
				// Stripe handling: if 'stripe' is allowed, keep base if present; if base not present, keep stripe_*; if not allowed, hide all stripe family
				if ( $id === 'stripe' ) {
					if ( ! $stripe_allowed ) { unset( $gateways[ $id ] ); }
					continue;
				}
				if ( strpos( $id, 'stripe_' ) === 0 ) {
					if ( ! $stripe_allowed ) {
						unset( $gateways[ $id ] );
						continue;
					}
					// If base stripe exists and is allowed, prefer showing only base; otherwise allow sub-methods
					if ( $has_base_stripe ) {
						unset( $gateways[ $id ] );
					}
					continue;
				}

				// For non-family gateways, enforce explicit allow list
				if ( ! isset( $allowed_map[ $id ] ) ) {
					unset( $gateways[ $id ] );
				}
			}
			return $gateways;
		} catch ( \Throwable $e ) {
			Logger::instance()->log( 'error', 'Gateway filter failed', [ 'error' => $e->getMessage() ] );
			return $gateways;
		}
	}

	private static function get_allowed_gateways_for_segment( string $segment ): ?array {
		$cache_key = 'zw_ms_pay_cfg_' . $segment;
		$cached = get_transient( $cache_key );
		if ( is_array( $cached ) ) { return $cached; }
		$primary = (string) get_option( 'zw_ms_primary_url', '' );
		$secret  = (string) get_option( 'zw_ms_primary_secret', '' );
		if ( ! $primary || ! $secret ) {
			Logger::instance()->log( 'warning', 'Primary URL or shared secret missing on Secondary; not enforcing', [ 'primary_url' => $primary ? 'set' : 'missing', 'secret' => $secret ? 'set' : 'missing' ] );
			return null;
		}
		$site_id = (string) get_option( 'zw_ms_site_id', '' );
		// Sign ONLY the route path (no query) to match server verify_hmac
		$unsigned_path = '/zw-ms/v1/payments-config';
		$method = 'GET';
		$timestamp = (string) time();
		$nonce = wp_generate_uuid4();
		$body = '';
		$signature = \ZeusWeb\Multishop\Rest\HMAC::sign( $method, $unsigned_path, $timestamp, $nonce, $body, $secret );
		$url = rtrim( $primary, '/' ) . '/wp-json' . $unsigned_path . '?site_id=' . rawurlencode( $site_id ) . '&segment=' . rawurlencode( $segment ) . '&t=' . rawurlencode( (string) $timestamp );
		$args = [ 'headers' => [ 'X-ZW-Timestamp' => $timestamp, 'X-ZW-Nonce' => $nonce, 'X-ZW-Signature' => $signature, 'Accept' => 'application/json' ], 'timeout' => 15 ];
		Logger::instance()->log( 'info', 'payments-config request', [ 'url' => $url, 'site_id' => $site_id, 'segment' => $segment ] );
		$response = wp_remote_get( $url, $args );
		if ( is_wp_error( $response ) ) { Logger::instance()->log( 'error', 'payments-config wp_error', [ 'error' => $response->get_error_message() ] ); return null; }
		$code = (int) wp_remote_retrieve_response_code( $response );
		$body_json = wp_remote_retrieve_body( $response );
		if ( $code !== 200 ) {
			Logger::instance()->log( 'warning', 'payments-config fetch non-200', [ 'code' => $code, 'body' => substr( $body_json, 0, 300 ) ] );
			return null;
		}
		$data = json_decode( $body_json, true );
		if ( ! is_array( $data ) || ! is_array( $data['allowed'] ?? null ) ) { Logger::instance()->log( 'warning', 'payments-config invalid body' ); return null; }
		$allowed = array_values( array_map( 'strval', $data['allowed'] ) );
		Logger::instance()->log( 'info', 'payments-config allowed gateways', [ 'segment' => $segment, 'allowed' => $allowed ] );
		set_transient( $cache_key, $allowed, 60 );
		return $allowed;
	}
}

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
			// If no gateways are allowed (no mapping or empty mapping), hide all.
			if ( empty( $allowed ) ) { return []; }

			$allowed_map = array_fill_keys( $allowed, true );
			$allow_stripe_family = isset( $allowed_map['stripe'] );

			foreach ( $gateways as $id => $g ) {
				// Handle Stripe family: only show base 'stripe' if allowed; hide all 'stripe_*'
				if ( $id === 'stripe' ) {
					if ( ! $allow_stripe_family ) { unset( $gateways[ $id ] ); }
					continue;
				}
				if ( strpos( $id, 'stripe_' ) === 0 ) {
					// Always hide sub-methods; user controls Stripe as a whole via 'stripe'
					unset( $gateways[ $id ] );
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

	private static function get_allowed_gateways_for_segment( string $segment ): array {
		$cache_key = 'zw_ms_pay_cfg_' . $segment;
		$cached = get_transient( $cache_key );
		if ( is_array( $cached ) ) { return $cached; }
		$primary = (string) get_option( 'zw_ms_primary_url', '' );
		$secret  = (string) get_option( 'zw_ms_primary_secret', '' );
		if ( ! $primary || ! $secret ) { return []; }
		$site_id = (string) get_option( 'zw_ms_site_id', '' );
		$path = '/zw-ms/v1/payments-config?site_id=' . rawurlencode( $site_id ) . '&segment=' . rawurlencode( $segment );
		$method = 'GET';
		$timestamp = (string) time();
		$nonce = wp_generate_uuid4();
		$body = '';
		$signature = \ZeusWeb\Multishop\Rest\HMAC::sign( $method, $path, $timestamp, $nonce, $body, $secret );
		$url = rtrim( $primary, '/' ) . '/wp-json' . $path;
		$args = [ 'headers' => [ 'X-ZW-Timestamp' => $timestamp, 'X-ZW-Nonce' => $nonce, 'X-ZW-Signature' => $signature, 'Accept' => 'application/json' ], 'timeout' => 15 ];
		$response = wp_remote_get( $url, $args );
		if ( is_wp_error( $response ) ) { return []; }
		$code = (int) wp_remote_retrieve_response_code( $response );
		$data = json_decode( wp_remote_retrieve_body( $response ), true );
		if ( $code !== 200 || ! is_array( $data ) || ! is_array( $data['allowed'] ?? null ) ) { return []; }
		$allowed = array_values( array_map( 'strval', $data['allowed'] ) );
		set_transient( $cache_key, $allowed, 10 * MINUTE_IN_SECONDS );
		return $allowed;
	}
}

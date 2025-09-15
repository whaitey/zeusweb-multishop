<?php

namespace ZeusWeb\Multishop\Rest;

use ZeusWeb\Multishop\Plugin;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class HMAC {
	public static function sign( string $method, string $path, string $timestamp, string $nonce, string $body, string $secret ): string {
		$payload = strtoupper( $method ) . "\n" . $path . "\n" . $timestamp . "\n" . $nonce . "\n" . hash( 'sha256', $body );
		return base64_encode( hash_hmac( 'sha256', $payload, $secret, true ) );
	}

	public static function verify( string $signature, string $method, string $path, string $timestamp, string $nonce, string $body, string $secret ): bool {
		$calc = self::sign( $method, $path, $timestamp, $nonce, $body, $secret );
		if ( ! hash_equals( $calc, $signature ) ) {
			return false;
		}
		$ts = intval( $timestamp );
		if ( abs( time() - $ts ) > 300 ) { // 5 minutes
			return false;
		}
		// Simple nonce cache to prevent replay: store for 10 minutes.
		$cache_key = 'zw_ms_nonce_' . md5( $nonce );
		if ( get_transient( $cache_key ) ) {
			return false;
		}
		set_transient( $cache_key, 1, 10 * MINUTE_IN_SECONDS );
		return true;
	}
}



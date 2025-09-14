<?php

namespace ZeusWeb\Multishop\Orders;

use ZeusWeb\Multishop\Logger\Logger;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class MirrorRetry {
	private const OPTION_QUEUE = 'zw_ms_mirror_queue';

	public static function init(): void {
		add_action( 'zw_ms_mirror_retry', [ __CLASS__, 'process_queue' ] );
	}

	public static function schedule_if_needed(): void {
		if ( get_option( 'zw_ms_mode', 'primary' ) !== 'secondary' ) { return; }
		if ( ! wp_next_scheduled( 'zw_ms_mirror_retry' ) ) {
			wp_schedule_event( time() + 600, 'hourly', 'zw_ms_mirror_retry' );
		}
	}

	public static function enqueue( array $payload ): void {
		$q = get_option( self::OPTION_QUEUE, [] );
		if ( ! is_array( $q ) ) { $q = []; }
		$payload['__ts'] = time();
		$payload['__retries'] = isset( $payload['__retries'] ) ? (int) $payload['__retries'] : 0;
		$q[] = $payload;
		update_option( self::OPTION_QUEUE, $q, false );
		Logger::instance()->log( 'info', 'Mirror enqueued', [ 'size' => count( $q ) ] );
	}

	public static function process_queue(): void {
		if ( get_option( 'zw_ms_mode', 'primary' ) !== 'secondary' ) { return; }
		$q = get_option( self::OPTION_QUEUE, [] );
		if ( empty( $q ) || ! is_array( $q ) ) { return; }
		$next = [];
		foreach ( $q as $item ) {
			$ok = self::send( $item );
			if ( ! $ok ) {
				$item['__retries'] = (int) ( $item['__retries'] ?? 0 ) + 1;
				// Simple backoff: keep item for future retries
				$next[] = $item;
			}
		}
		update_option( self::OPTION_QUEUE, $next, false );
		Logger::instance()->log( 'info', 'Mirror queue processed', [ 'remaining' => count( $next ) ] );
	}

	private static function send( array $payload ): bool {
		try {
			$primary = (string) get_option( 'zw_ms_primary_url', '' );
			$primary_secret = (string) get_option( 'zw_ms_primary_secret', '' );
			if ( ! $primary || ! $primary_secret ) { return false; }
			$path   = '/zw-ms/v1/mirror-order';
			$url    = rtrim( $primary, '/' ) . '/wp-json' . $path;
			$method = 'POST';
			$timestamp = (string) time();
			$nonce     = wp_generate_uuid4();
			$body = wp_json_encode( $payload );
			$signature = \ZeusWeb\Multishop\Rest\HMAC::sign( $method, $path, $timestamp, $nonce, $body, $primary_secret );
			$args = [ 'headers' => [ 'Content-Type' => 'application/json', 'X-ZW-Timestamp' => $timestamp, 'X-ZW-Nonce' => $nonce, 'X-ZW-Signature' => $signature, 'Accept' => 'application/json' ], 'body' => $body, 'timeout' => 20 ];
			$response = wp_remote_post( $url, $args );
			if ( is_wp_error( $response ) ) { return false; }
			$code = (int) wp_remote_retrieve_response_code( $response );
			return $code === 200;
		} catch ( \Throwable $e ) {
			Logger::instance()->log( 'error', 'Mirror retry send failed', [ 'error' => $e->getMessage() ] );
			return false;
		}
	}
}

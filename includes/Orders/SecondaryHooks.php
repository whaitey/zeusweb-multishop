<?php

namespace ZeusWeb\Multishop\Orders;

use ZeusWeb\Multishop\Segments\Manager as SegmentManager;
use ZeusWeb\Multishop\Logger\Logger;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class SecondaryHooks {
	public static function init(): void {
		add_action( 'woocommerce_order_status_processing', [ __CLASS__, 'on_order_paid' ], 10, 2 );
		add_action( 'woocommerce_thankyou', [ __CLASS__, 'on_thankyou_notice_only' ], 20, 1 );
		add_filter( 'woocommerce_order_number', [ __CLASS__, 'maybe_use_primary_order_number' ], 10, 2 );
	}

	public static function maybe_use_primary_order_number( $order_number, $order ) {
		if ( get_option( 'zw_ms_mode', 'primary' ) !== 'secondary' ) { return $order_number; }
		$primary_num = (string) $order->get_meta( '_zw_ms_primary_order_number' );
		return $primary_num !== '' ? $primary_num : $order_number;
	}

	public static function on_thankyou_notice_only( $order_id ): void {
		// Secondary never sends emails; rely on notices and keys attached to items
	}

	public static function on_order_paid( $order_id, $order ): void {
		$mode = get_option( 'zw_ms_mode', 'primary' );
		if ( $mode !== 'secondary' ) {
			return;
		}
		// Prevent double-processing
		if ( 'yes' === (string) $order->get_meta( '_zw_ms_allocated' ) ) {
			return;
		}
		try {
			self::mirror_order_to_primary( $order );
			$order->update_meta_data( '_zw_ms_allocated', 'yes' );
			$order->save();
		} catch ( \Throwable $e ) {
			Logger::instance()->log( 'error', 'Mirror order failed', [ 'order_id' => $order_id, 'error' => $e->getMessage() ] );
		}
	}

	private static function mirror_order_to_primary( \WC_Order $order ): void {
		$primary = (string) get_option( 'zw_ms_primary_url', '' );
		if ( ! $primary ) {
			Logger::instance()->log( 'error', 'Primary URL not set for Secondary mirror', [ 'order_id' => $order->get_id() ] );
			return;
		}
		$primary_secret = (string) get_option( 'zw_ms_primary_secret', '' );
		if ( ! $primary_secret ) {
			Logger::instance()->log( 'error', 'Primary shared secret not set on Secondary (mirror)', [ 'order_id' => $order->get_id() ] );
			return;
		}
		$path   = '/zw-ms/v1/mirror-order';
		$url    = rtrim( $primary, '/' ) . '/wp-json' . $path;
		$method = 'POST';
		$timestamp = (string) time();
		$nonce     = wp_generate_uuid4();
		$body_data = [
			'site_id' => get_option( 'zw_ms_site_id' ),
			'order_id' => (string) $order->get_id(),
			'remote_order_number' => (string) $order->get_order_number(),
			'customer_segment' => SegmentManager::is_business() ? 'business' : 'consumer',
			'customer_email' => (string) $order->get_billing_email(),
			'items' => self::build_items_with_skus( $order ),
		];
		$body = wp_json_encode( $body_data );
		$signature = \ZeusWeb\Multishop\Rest\HMAC::sign( $method, $path, $timestamp, $nonce, $body, $primary_secret );
		$args = [
			'headers' => [
				'Content-Type'     => 'application/json',
				'X-ZW-Timestamp'    => $timestamp,
				'X-ZW-Nonce'        => $nonce,
				'X-ZW-Signature'    => $signature,
				'Accept'            => 'application/json',
			],
			'body'    => $body,
			'timeout' => 20,
		];
		$response = wp_remote_post( $url, $args );
		if ( is_wp_error( $response ) ) {
			throw new \RuntimeException( $response->get_error_message() );
		}
		$code = (int) wp_remote_retrieve_response_code( $response );
		$resp_body = wp_remote_retrieve_body( $response );
		if ( $code !== 200 ) {
			Logger::instance()->log( 'error', 'Primary mirror HTTP error', [ 'order_id' => $order->get_id(), 'status' => $code, 'body' => $resp_body ] );
			return;
		}
		$data = json_decode( $resp_body, true );
		if ( is_array( $data ) ) {
			$primary_order_number = (string) ( $data['order_number'] ?? '' );
			if ( $primary_order_number !== '' ) {
				$order->update_meta_data( '_zw_ms_primary_order_number', $primary_order_number );
				$order->save();
			}
			if ( isset( $data['allocations'] ) && is_array( $data['allocations'] ) ) {
				self::attach_keys_to_order( $order, $data['allocations'] );
				Logger::instance()->log( 'info', 'Secondary attached keys from Primary mirror', [ 'order_id' => $order->get_id() ] );
			}
		}
	}

	private static function build_items_with_skus( \WC_Order $order ): array {
		$items = [];
		foreach ( $order->get_items() as $item_id => $item ) {
			$product   = $item->get_product();
			if ( ! $product ) { continue; }
			$is_bundle_container = method_exists( $product, 'is_type' ) && $product->is_type( 'bundle' );
			if ( $is_bundle_container ) { continue; }
			$sku = (string) $product->get_sku();
			$items[] = [
				'sku'       => $sku,
				'quantity'  => (int) $item->get_quantity(),
			];
		}
		return $items;
	}

	private static function attach_keys_to_order( \WC_Order $order, array $allocations ): void {
		$shortage = (string) get_option( 'zw_ms_shortage_message', '' );
		foreach ( $allocations as $alloc ) {
			$product_id = (int) ( $alloc['product_id'] ?? 0 );
			$keys       = is_array( $alloc['keys'] ?? null ) ? $alloc['keys'] : [];
			$pending    = (int) ( $alloc['pending'] ?? 0 );
			if ( $product_id <= 0 ) { continue; }
			foreach ( $order->get_items() as $item_id => $item ) {
				if ( (int) $item->get_product_id() !== $product_id ) { continue; }
				if ( ! empty( $keys ) ) {
					wc_add_order_item_meta( $item_id, '_zw_ms_keys', implode( "\n", array_map( 'sanitize_text_field', $keys ) ) );
				}
				if ( $pending > 0 && $shortage ) {
					wc_add_order_item_meta( $item_id, '_zw_ms_shortage', $shortage );
				}
			}
		}
		$order->save();
	}
}



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
	}

	public static function on_order_paid( $order_id, $order ): void {
		$mode = get_option( 'zw_ms_mode', 'primary' );
		if ( $mode !== 'secondary' ) {
			return;
		}
		try {
			self::request_allocation( $order );
		} catch ( \Throwable $e ) {
			Logger::instance()->log( 'error', 'Allocation request failed', [ 'order_id' => $order_id, 'error' => $e->getMessage() ] );
		}
	}

	private static function request_allocation( \WC_Order $order ): void {
		$primary = (string) get_option( 'zw_ms_primary_url', '' );
		if ( ! $primary ) {
			return;
		}
		$path   = '/wp-json/zw-ms/v1/allocate-keys';
		$url    = rtrim( $primary, '/' ) . $path;
		$method = 'POST';
		$timestamp = (string) time();
		$nonce     = wp_generate_uuid4();
		$body_data = [
			'site_id' => get_option( 'zw_ms_site_id' ),
			'order_id' => (string) $order->get_id(),
			'customer_segment' => SegmentManager::is_business() ? 'business' : 'consumer',
			'items' => self::build_allocation_items( $order ),
		];
		$body = wp_json_encode( $body_data );

		$secret = get_option( 'zw_ms_secret' );
		$signature = \ZeusWeb\Multishop\Rest\HMAC::sign( $method, $path, $timestamp, $nonce, $body, (string) $secret );

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
		$data = json_decode( wp_remote_retrieve_body( $response ), true );
		if ( is_array( $data ) && isset( $data['allocations'] ) && is_array( $data['allocations'] ) ) {
			self::attach_keys_to_order( $order, $data['allocations'] );
		}
	}

	private static function build_allocation_items( \WC_Order $order ): array {
		$items = [];
		foreach ( $order->get_items() as $item_id => $item ) {
			// If this is a bundled child item (WooCommerce Product Bundles), include it; skip container bundle items
			$bundled_by = $item->get_meta( '_bundled_by', true );
			$product   = $item->get_product();
			$is_bundle_container = $product && method_exists( $product, 'is_type' ) && $product->is_type( 'bundle' );
			if ( $is_bundle_container ) {
				continue;
			}
			// Include all non-container items (simple, variation, or bundled children)
			$items[] = [
				'product_id'   => (int) $item->get_product_id(),
				'variation_id' => (int) $item->get_variation_id(),
				'quantity'     => (int) $item->get_quantity(),
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
			// Find matching order items
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



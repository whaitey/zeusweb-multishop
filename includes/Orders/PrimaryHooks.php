<?php

namespace ZeusWeb\Multishop\Orders;

use ZeusWeb\Multishop\Keys\Service as KeysService;
use ZeusWeb\Multishop\Logger\Logger;
use ZeusWeb\Multishop\Emails\CustomSender;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class PrimaryHooks {
	public static function init(): void {
		add_action( 'woocommerce_order_status_processing', [ __CLASS__, 'on_order_paid' ], 10, 2 );
	}

	public static function on_order_paid( $order_id, $order ): void {
		$mode = get_option( 'zw_ms_mode', 'primary' );
		if ( $mode !== 'primary' ) {
			return;
		}
		try {
			self::allocate_for_order( $order );
		} catch ( \Throwable $e ) {
			Logger::instance()->log( 'error', 'Primary allocation failed', [ 'order_id' => $order_id, 'error' => $e->getMessage() ] );
		}
	}

	private static function allocate_for_order( \WC_Order $order ): void {
		$items = [];
		foreach ( $order->get_items() as $item ) {
			$items[] = [
				'product_id'   => (int) $item->get_product_id(),
				'variation_id' => (int) $item->get_variation_id(),
				'quantity'     => (int) $item->get_quantity(),
			];
		}
		$site_id = (string) get_option( 'zw_ms_site_id', 'primary' );
		$alloc   = KeysService::allocate_for_items( $site_id, (string) $order->get_id(), $items );
		self::attach_keys_to_order( $order, $alloc );
		self::maybe_send_customer_email( $order );
		if ( CustomSender::should_send_custom_email_now( $order ) ) {
			CustomSender::send_order_keys_email( $order );
		}
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

	private static function maybe_send_customer_email( \WC_Order $order ): void {
		try {
			$status = $order->get_status();
			$emails = function_exists( 'WC' ) && WC()->mailer() ? WC()->mailer()->get_emails() : [];
			if ( empty( $emails ) ) { return; }
			foreach ( $emails as $email ) {
				if ( ! method_exists( $email, 'is_enabled' ) || ! $email->is_enabled() ) { continue; }
				if ( $status === 'processing' && $email instanceof \WC_Email_Customer_Processing_Order ) {
					$email->trigger( $order->get_id() );
				}
				if ( $status === 'completed' && $email instanceof \WC_Email_Customer_Completed_Order ) {
					$email->trigger( $order->get_id() );
				}
			}
		} catch ( \Throwable $e ) {
			Logger::instance()->log( 'error', 'Failed to send customer email after allocation', [ 'order_id' => $order->get_id(), 'error' => $e->getMessage() ] );
		}
	}
}



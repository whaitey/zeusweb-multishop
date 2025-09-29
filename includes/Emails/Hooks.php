<?php

namespace ZeusWeb\Multishop\Emails;

if ( ! defined( 'ABSPATH' ) ) { exit; }

class Hooks {
	public static function init(): void {
		// Render keys/shortage after the order table so it's theme-agnostic
		add_action( 'woocommerce_email_after_order_table', [ __CLASS__, 'render_keys_after_table' ], 12, 4 );
		// Gate Woo emails until all keys are present (avoid provider errors when shortage)
		add_filter( 'woocommerce_email_enabled_customer_processing_order', [ __CLASS__, 'maybe_gate_email' ], 10, 2 );
		add_filter( 'woocommerce_email_enabled_customer_completed_order', [ __CLASS__, 'maybe_gate_email' ], 10, 2 );
	}

	public static function render_keys_after_table( $order, $sent_to_admin, $plain_text, $email ): void {
		if ( $sent_to_admin || ! $order || ! is_a( $order, 'WC_Order' ) ) { return; }
		$has_any = false; $html = '';
		foreach ( $order->get_items() as $item_id => $item ) {
			$keys = (string) wc_get_order_item_meta( $item_id, '_zw_ms_keys', true );
			$shortage = (string) wc_get_order_item_meta( $item_id, '_zw_ms_shortage', true );
			if ( $keys !== '' ) {
				$has_any = true;
				$html .= '<p><strong>' . esc_html__( 'Your keys:', 'zeusweb-multishop' ) . '</strong><br />' . nl2br( esc_html( $keys ) ) . '</p>';
			}
			if ( $shortage !== '' ) {
				$has_any = true;
				$html .= '<p><em>' . wp_kses_post( $shortage ) . '</em></p>';
			}
		}
		if ( $has_any ) {
			echo '<h2 style="margin-top:12px;">' . esc_html__( 'Digital delivery', 'zeusweb-multishop' ) . '</h2>' . $html; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		}
	}

	public static function maybe_gate_email( bool $enabled, $order ): bool {
		// Don't affect admin screens
		if ( is_admin() ) { return $enabled; }
		if ( ! $order || ! is_a( $order, 'WC_Order' ) ) { return $enabled; }
		// If any non-bundle item lacks keys, suppress this Woo email
		foreach ( $order->get_items() as $item_id => $item ) {
			$product = $item->get_product();
			$is_bundle_container = $product && method_exists( $product, 'is_type' ) && $product->is_type( 'bundle' );
			if ( $is_bundle_container ) { continue; }
			$kv = (string) wc_get_order_item_meta( $item_id, '_zw_ms_keys', true );
			if ( $kv === '' ) { return false; }
		}
		return $enabled;
	}
}



<?php

namespace ZeusWeb\Multishop\Checkout;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Notices {
	public static function init(): void {
		add_action( 'woocommerce_thankyou', [ __CLASS__, 'maybe_show_shortage_notice' ], 20, 1 );
		add_action( 'woocommerce_view_order', [ __CLASS__, 'maybe_show_shortage_notice' ], 20, 1 );
		add_action( 'woocommerce_thankyou', [ __CLASS__, 'maybe_render_keys_on_thankyou' ], 25, 1 );
	}

	public static function maybe_show_shortage_notice( $order_id ): void {
		$order = wc_get_order( $order_id );
		if ( ! $order ) { return; }
		if ( ! self::order_has_shortage( $order ) ) { return; }
		$default_msg = __( 'Some keys are delayed and will arrive within 24 hours.', 'zeusweb-multishop' );
		$shortage_setting = (string) get_option( 'zw_ms_shortage_message', '' );
		$message = $shortage_setting !== '' ? $shortage_setting : $default_msg;
		// Use Woo notice system if available
		if ( function_exists( 'wc_print_notice' ) ) {
			wc_print_notice( wp_kses_post( $message ), 'notice' );
			return;
		}
		// Fallback render
		echo '<div class="woocommerce-info">' . wp_kses_post( $message ) . '</div>';
	}

	private static function order_has_shortage( \WC_Order $order ): bool {
		foreach ( $order->get_items() as $item_id => $item ) {
			$note = wc_get_order_item_meta( $item_id, '_zw_ms_shortage', true );
			if ( ! empty( $note ) ) { return true; }
		}
		return false;
	}

	public static function maybe_render_keys_on_thankyou( $order_id ): void {
		$order = wc_get_order( $order_id );
		if ( ! $order ) { return; }
		if ( get_option( 'zw_ms_mode', 'primary' ) !== 'secondary' ) { return; }
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
			echo '<div class="woocommerce-order">' . $html . '</div>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		}
	}
}

<?php

namespace ZeusWeb\Multishop\Emails;

if ( ! defined( 'ABSPATH' ) ) { exit; }

class Hooks {
	public static function init(): void {
		// Render keys/shortage after the order table so it's theme-agnostic
		add_action( 'woocommerce_email_after_order_table', [ __CLASS__, 'render_keys_after_table' ], 12, 4 );
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
}



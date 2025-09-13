<?php

namespace ZeusWeb\Multishop\Emails;

use ZeusWeb\Multishop\Products\Meta as ProductMeta;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Hooks {
	public static function init(): void {
		add_action( 'woocommerce_email_order_meta', [ __CLASS__, 'append_product_custom_emails' ], 20, 3 );
		add_action( 'woocommerce_order_item_meta_end', [ __CLASS__, 'render_keys_in_emails' ], 10, 3 );
	}

	public static function append_product_custom_emails( $order, $sent_to_admin, $plain_text ): void {
		if ( $sent_to_admin ) {
			return;
		}
		if ( ! $order || ! is_a( $order, 'WC_Order' ) ) {
			return;
		}
		$items = $order->get_items();
		$content = '';
		foreach ( $items as $item ) {
			$product_id = $item->get_product_id();
			$custom     = get_post_meta( $product_id, ProductMeta::META_CUSTOM_EMAIL, true );
			if ( $custom ) {
				$content .= '<div class="zw-ms-product-email"><h3>' . esc_html( get_the_title( $product_id ) ) . '</h3>' . wp_kses_post( $custom ) . '</div>';
			}
		}
		if ( $content ) {
			echo '<hr />' . $content; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		}
	}

	public static function render_keys_in_emails( $item_id, $item, $order ): void {
		if ( ! is_a( $order, 'WC_Order' ) ) { return; }
		$keys = wc_get_order_item_meta( $item_id, '_zw_ms_keys', true );
		$shortage = wc_get_order_item_meta( $item_id, '_zw_ms_shortage', true );
		if ( $keys ) {
			echo '<p><strong>' . esc_html__( 'Your keys:', 'zeusweb-multishop' ) . '</strong><br />' . nl2br( esc_html( $keys ) ) . '</p>';
		}
		if ( $shortage ) {
			echo '<p><em>' . wp_kses_post( $shortage ) . '</em></p>';
		}
	}
}



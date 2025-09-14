<?php

namespace ZeusWeb\Multishop\Emails;

use ZeusWeb\Multishop\Products\Meta as ProductMeta;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Hooks {
	/** @var array<int,bool> */
	private static $items_with_keys_placeholder = [];

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
		foreach ( $items as $item_id => $item ) {
			$product_id = $item->get_product_id();
			$custom     = get_post_meta( $product_id, ProductMeta::META_CUSTOM_EMAIL, true );
			if ( $custom ) {
				$processed = self::apply_placeholders( (string) $custom, $order, (int) $item_id, (int) $product_id );
				$content .= '<div class="zw-ms-product-email"><h3>' . esc_html( get_the_title( $product_id ) ) . '</h3>' . $processed . '</div>';
			}
		}
		if ( $content ) {
			echo '<hr />' . $content; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		}
	}

	public static function render_keys_in_emails( $item_id, $item, $order ): void {
		if ( ! is_a( $order, 'WC_Order' ) ) { return; }
		if ( isset( self::$items_with_keys_placeholder[ (int) $item_id ] ) ) { return; }
		$keys = wc_get_order_item_meta( $item_id, '_zw_ms_keys', true );
		$shortage = wc_get_order_item_meta( $item_id, '_zw_ms_shortage', true );
		if ( $keys ) {
			echo '<p><strong>' . esc_html__( 'Your keys:', 'zeusweb-multishop' ) . '</strong><br />' . nl2br( esc_html( $keys ) ) . '</p>';
		}
		if ( $shortage ) {
			echo '<p><em>' . wp_kses_post( $shortage ) . '</em></p>';
		}
	}

	private static function apply_placeholders( string $template, \WC_Order $order, int $item_id, int $product_id ): string {
		$replacements = [];
		$replacements['{product_name}'] = get_the_title( $product_id ) ?: '';
		$keys_raw = (string) wc_get_order_item_meta( $item_id, '_zw_ms_keys', true );
		$shortage = (string) wc_get_order_item_meta( $item_id, '_zw_ms_shortage', true );
		$replacements['{keys_raw}'] = $keys_raw;
		$item = $order->get_item( $item_id );
		$replacements['{quantity}'] = $item ? (string) $item->get_quantity() : '1';
		$replacements['{shortage_note}'] = $shortage;
		$keys_html = '';
		if ( $keys_raw !== '' ) {
			$keys_html .= '<ul class="zw-ms-keys">';
			foreach ( preg_split( "/\r\n|\r|\n/", $keys_raw ) as $k ) {
				$k = trim( (string) $k );
				if ( $k === '' ) { continue; }
				$keys_html .= '<li>' . esc_html( $k ) . '</li>';
			}
			$keys_html .= '</ul>';
		}
		$has = ( strpos( $template, '{keys}' ) !== false ) || ( strpos( $template, '{keys_html}' ) !== false ) || ( strpos( $template, '{keys_raw}' ) !== false );
		if ( $has ) { self::$items_with_keys_placeholder[ (int) $item_id ] = true; }
		$replacements['{keys}'] = $keys_html ?: nl2br( esc_html( $keys_raw ) );
		$replacements['{keys_html}'] = $keys_html;
		$processed = strtr( $template, $replacements );
		return wp_kses_post( $processed );
	}
}



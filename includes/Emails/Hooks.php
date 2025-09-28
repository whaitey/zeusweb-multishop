<?php

namespace ZeusWeb\Multishop\Emails;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Hooks {
	/** @var array<int,bool> */
	private static $items_with_keys_placeholder = [];

	public static function init(): void {
		// Render per-item keys in Woo emails
		add_action( 'woocommerce_order_item_meta_end', [ __CLASS__, 'render_keys_in_emails' ], 10, 3 );
		// Append per-product custom content (if set)
		add_action( 'woocommerce_email_order_meta', [ __CLASS__, 'append_product_custom_emails' ], 20, 3 );
		// Ensure keys are visible even if templates skip item meta blocks
		add_action( 'woocommerce_email_after_order_table', [ __CLASS__, 'render_keys_section_after_table' ], 10, 4 );
	}

	public static function render_keys_in_emails( $item_id, $item, $order ): void {
		if ( ! is_a( $order, 'WC_Order' ) ) { return; }
		if ( isset( self::$items_with_keys_placeholder[ (int) $item_id ] ) ) { return; }
		$keys = (string) wc_get_order_item_meta( $item_id, '_zw_ms_keys', true );
		$shortage = (string) wc_get_order_item_meta( $item_id, '_zw_ms_shortage', true );
		if ( $keys !== '' ) {
			echo '<p><strong>' . esc_html__( 'Your keys:', 'zeusweb-multishop' ) . '</strong><br />' . nl2br( esc_html( $keys ) ) . '</p>';
		}
		if ( $shortage !== '' ) {
			echo '<p><em>' . wp_kses_post( $shortage ) . '</em></p>';
		}
	}

	public static function append_product_custom_emails( $order, $sent_to_admin, $plain_text ): void {
		if ( $sent_to_admin ) { return; }
		if ( ! $order || ! is_a( $order, 'WC_Order' ) ) { return; }
		$content = '';
		foreach ( $order->get_items() as $item_id => $item ) {
			$product_id = (int) $item->get_product_id();
			$custom     = get_post_meta( $product_id, \ZeusWeb\Multishop\Products\Meta::META_CUSTOM_EMAIL, true );
			if ( $custom ) {
				$processed = self::apply_placeholders( (string) $custom, $order, (int) $item_id, (int) $product_id );
				$content .= '<div class="zw-ms-product-email"><h3>' . esc_html( get_the_title( $product_id ) ) . '</h3>' . $processed . '</div>';
			}
		}
		if ( $content ) {
			echo '<hr />' . $content; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
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
		// Preserve author formatting if HTML exists; otherwise convert plain text newlines to paragraphs
		$has_blocks = (bool) preg_match( '/<\s*(p|div|ul|ol|li|br|h1|h2|h3|h4|h5|h6|table|tr|td|th|blockquote)\b/i', $processed );
		if ( ! $has_blocks && function_exists( 'wpautop' ) ) {
			$processed = wpautop( esc_html( $processed ) );
		} else {
			$processed = wp_kses_post( $processed );
		}
		return $processed;
	}

	public static function render_keys_section_after_table( $order, $sent_to_admin, $plain_text, $email ): void {
		if ( $sent_to_admin ) { return; }
		if ( ! $order || ! is_a( $order, 'WC_Order' ) ) { return; }
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



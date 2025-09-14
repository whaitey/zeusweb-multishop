<?php

namespace ZeusWeb\Multishop\Emails;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class CustomSender {
	public static function send_order_keys_email( \WC_Order $order ): void {
		$to = $order->get_billing_email();
		if ( ! $to ) { return; }
		$subject_tpl = (string) get_option( 'zw_ms_custom_email_subject', 'Your {site_name} order keys (#{order_number})' );
		$subject = strtr( $subject_tpl, [
			'{site_name}' => wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES ),
			'{order_number}' => (string) $order->get_order_number(),
		] );
		$headers = [ 'Content-Type: text/html; charset=UTF-8' ];
		$body = self::build_email_html( $order );
		wp_mail( $to, $subject, $body, $headers );
	}

	private static function build_email_html( \WC_Order $order ): string {
		$items_html = '';
		foreach ( $order->get_items() as $item_id => $item ) {
			$product_id = (int) $item->get_product_id();
			$title = get_the_title( $product_id ) ?: (string) $product_id;
			$qty   = (int) $item->get_quantity();
			$keys_raw = (string) wc_get_order_item_meta( $item_id, '_zw_ms_keys', true );
			$shortage = (string) wc_get_order_item_meta( $item_id, '_zw_ms_shortage', true );
			$keys_html = '';
			if ( $keys_raw !== '' ) {
				$keys_html .= '<ul style="margin:0;padding-left:18px;">';
				foreach ( preg_split( "/\r\n|\r|\n/", $keys_raw ) as $k ) {
					$k = trim( (string) $k );
					if ( $k === '' ) { continue; }
					$keys_html .= '<li>' . esc_html( $k ) . '</li>';
				}
				$keys_html .= '</ul>';
			}
			// Per-product custom content with placeholders
			$custom = get_post_meta( $product_id, \ZeusWeb\Multishop\Products\Meta::META_CUSTOM_EMAIL, true );
			$custom_processed = '';
			if ( $custom ) {
				$custom_processed = self::apply_placeholders( (string) $custom, $title, $qty, $keys_raw, $keys_html, $shortage );
			}
			$items_html .= '<div style="margin:16px 0 24px 0;">';
			$items_html .= '<h3 style="margin:0 0 6px 0;font-size:16px;">' . esc_html( $title ) . ' (x' . esc_html( (string) $qty ) . ')</h3>';
			if ( $custom_processed ) {
				$items_html .= $custom_processed;
			} else {
				if ( $keys_html ) {
					$items_html .= '<p><strong>' . esc_html__( 'Your keys:', 'zeusweb-multishop' ) . '</strong></p>' . $keys_html;
				}
				if ( $shortage ) {
					$items_html .= '<p><em>' . wp_kses_post( $shortage ) . '</em></p>';
				}
			}
			$items_html .= '</div>';
		}
		$wrapper = '<div style="font-family:Arial,Helvetica,sans-serif;font-size:14px;line-height:1.5;color:#222;">{items_html}</div>';
		return strtr( $wrapper, [ '{items_html}' => $items_html ] );
	}

	private static function apply_placeholders( string $template, string $product_name, int $qty, string $keys_raw, string $keys_html, string $shortage ): string {
		$replacements = [
			'{product_name}' => $product_name,
			'{quantity}' => (string) $qty,
			'{keys_raw}' => $keys_raw,
			'{keys_html}' => $keys_html,
			'{keys}' => $keys_html ?: nl2br( esc_html( $keys_raw ) ),
			'{shortage_note}' => $shortage,
		];
		return wp_kses_post( strtr( $template, $replacements ) );
	}
}



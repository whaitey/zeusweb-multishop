<?php

namespace ZeusWeb\Multishop\Emails;

use ZeusWeb\Multishop\Logger\Logger;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class CustomSender {
	public static function send_order_keys_email( \WC_Order $order ): void {
		$to = $order->get_billing_email();
		if ( ! $to ) { return; }
		$subject_tpl = (string) get_option( 'zw_ms_custom_email_subject', 'Your {site_name} order keys (#{order_number})' );
		$display_order_number = (string) $order->get_meta( '_zw_ms_remote_order_number' );
		if ( $display_order_number === '' ) {
			$display_order_number = (string) $order->get_order_number();
		}
		$subject = strtr( $subject_tpl, [
			'{site_name}' => wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES ),
			'{order_number}' => $display_order_number,
		] );
		$body_inner = self::build_email_html( $order );

		$sent = false;
		try {
			if ( function_exists( 'WC' ) && WC()->mailer() ) {
				$mailer = WC()->mailer();
				$wrapped = $mailer->wrap_message( __( 'Your keys', 'zeusweb-multishop' ), $body_inner );
				$sent = (bool) $mailer->send( $to, $subject, $wrapped );
				if ( ! $sent ) {
					// Fallback to wp_mail if Woo mailer failed
					$headers = [ 'Content-Type: text/html; charset=UTF-8' ];
					$sent = (bool) wp_mail( $to, $subject, $body_inner, $headers );
				}
			} else {
				$headers = [ 'Content-Type: text/html; charset=UTF-8' ];
				$sent = (bool) wp_mail( $to, $subject, $body_inner, $headers );
			}
		} catch ( \Throwable $e ) {
			Logger::instance()->log( 'error', 'Custom email send failed', [ 'order_id' => $order->get_id(), 'to' => $to, 'error' => $e->getMessage() ] );
			return;
		}
		Logger::instance()->log( $sent ? 'info' : 'error', 'Custom email sent', [ 'order_id' => $order->get_id(), 'to' => $to, 'sent' => $sent ] );
	}

	public static function should_send_custom_email_now( \WC_Order $order ): bool {
		// Custom-only setting
		if ( get_option( 'zw_ms_enable_custom_email_only', 'no' ) === 'yes' ) {
			return true;
		}
		// If Woo customer emails for current status are disabled, send our custom email
		$status = $order->get_status();
		if ( function_exists( 'WC' ) && WC()->mailer() ) {
			$emails = WC()->mailer()->get_emails();
			$processing_enabled = false;
			$completed_enabled = false;
			foreach ( $emails as $email ) {
				if ( $email instanceof \WC_Email_Customer_Processing_Order && method_exists( $email, 'is_enabled' ) ) {
					$processing_enabled = $email->is_enabled();
				}
				if ( $email instanceof \WC_Email_Customer_Completed_Order && method_exists( $email, 'is_enabled' ) ) {
					$completed_enabled = $email->is_enabled();
				}
			}
			if ( $status === 'processing' && ! $processing_enabled ) { return true; }
			if ( $status === 'completed' && ! $completed_enabled ) { return true; }
		}
		return false;
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



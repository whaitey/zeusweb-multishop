<?php

namespace ZeusWeb\Multishop\Checkout;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Notices {
	public static function init(): void {
		add_action( 'woocommerce_thankyou', [ __CLASS__, 'maybe_show_shortage_notice' ], 20, 1 );
		add_action( 'woocommerce_view_order', [ __CLASS__, 'maybe_show_shortage_notice' ], 20, 1 );
		// Enforce blacklist on checkout validation (both IP and email)
		add_action( 'woocommerce_after_checkout_validation', [ __CLASS__, 'enforce_blacklist' ], 10, 2 );
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

	public static function enforce_blacklist( $data, $errors ): void {
		if ( is_wp_error( $errors ) ) { return; }
		$blocked_ips_raw = (string) get_option( 'zw_ms_blacklist_ips', '' );
		$blocked_emails_raw = (string) get_option( 'zw_ms_blacklist_emails', '' );
		$blocked_ips = array_filter( array_map( 'trim', preg_split( '/\r?\n/', $blocked_ips_raw ) ) );
		$blocked_emails = array_filter( array_map( 'strtolower', array_map( 'trim', preg_split( '/\r?\n/', $blocked_emails_raw ) ) ) );

		$client_ip = wc_get_server_protocol() ? WC_Geolocation::get_ip_address() : ( $_SERVER['REMOTE_ADDR'] ?? '' );
		$client_ip = is_string( $client_ip ) ? trim( $client_ip ) : '';
		$billing_email = isset( $data['billing_email'] ) ? strtolower( trim( (string) $data['billing_email'] ) ) : '';

		$blocked = false;
		if ( $client_ip && in_array( $client_ip, $blocked_ips, true ) ) { $blocked = true; }
		if ( $billing_email && in_array( $billing_email, $blocked_emails, true ) ) { $blocked = true; }

		if ( $blocked ) {
			if ( function_exists( 'wc_add_notice' ) ) {
				wc_add_notice( __( 'Your order cannot be processed at this time.', 'zeusweb-multishop' ), 'error' );
			}
			if ( is_object( $errors ) && method_exists( $errors, 'add' ) ) {
				$errors->add( 'zw_ms_blacklist', __( 'Your order cannot be processed at this time.', 'zeusweb-multishop' ) );
			}
		}
	}
}

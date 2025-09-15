<?php

namespace ZeusWeb\Multishop\Orders;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class OrderNumbers {
	public static function init(): void {
		add_filter( 'woocommerce_order_number', [ __CLASS__, 'format_order_number' ], 10, 2 );
	}

	public static function format_order_number( $order_number, $order ) {
		try {
			// If this is a mirrored order on the Primary with a remote order number, use it verbatim
			$remote_num = (string) $order->get_meta( '_zw_ms_remote_order_number' );
			if ( $remote_num !== '' ) {
				return $remote_num;
			}
			$origin_code = (string) $order->get_meta( '_zw_ms_origin_site_code' );
			if ( $origin_code === '' ) {
				$origin_code = (string) get_option( 'zw_ms_site_code', '1' );
			}
			$origin_code = preg_replace( '/[^0-9A-Za-z_-]/', '', $origin_code );
			if ( $origin_code === '' ) { $origin_code = '1'; }
			return $origin_code . (string) $order->get_id();
		} catch ( \Throwable $e ) {
			return $order_number;
		}
	}
}

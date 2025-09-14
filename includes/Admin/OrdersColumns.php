<?php

namespace ZeusWeb\Multishop\Admin;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class OrdersColumns {
	public static function init(): void {
		// Show only on Primary where it matters most, but harmless elsewhere
		add_filter( 'manage_edit-shop_order_columns', [ __CLASS__, 'add_columns' ], 20 );
		add_action( 'manage_shop_order_posts_custom_column', [ __CLASS__, 'render_column' ], 20, 2 );
	}

	public static function add_columns( array $columns ): array {
		// Insert our columns near order_total
		$new = [];
		foreach ( $columns as $key => $label ) {
			$new[ $key ] = $label;
			if ( $key === 'order_total' ) {
				$new['zw_ms_origin']  = __( 'Origin Site', 'zeusweb-multishop' );
				$new['zw_ms_segment'] = __( 'Segment', 'zeusweb-multishop' );
			}
		}
		// If not inserted, append
		if ( ! isset( $new['zw_ms_origin'] ) ) {
			$new['zw_ms_origin']  = __( 'Origin Site', 'zeusweb-multishop' );
			$new['zw_ms_segment'] = __( 'Segment', 'zeusweb-multishop' );
		}
		return $new;
	}

	public static function render_column( string $column, int $post_id ): void {
		if ( $column === 'zw_ms_origin' ) {
			$order = function_exists( 'wc_get_order' ) ? wc_get_order( $post_id ) : null;
			$origin = $order ? (string) $order->get_meta( '_zw_ms_remote_site_id' ) : '';
			echo $origin !== '' ? esc_html( $origin ) : 'local';
			return;
		}
		if ( $column === 'zw_ms_segment' ) {
			$order = function_exists( 'wc_get_order' ) ? wc_get_order( $post_id ) : null;
			$segment = $order ? (string) $order->get_meta( '_zw_ms_remote_segment' ) : '';
			echo $segment !== '' ? esc_html( ucfirst( $segment ) ) : '';
			return;
		}
	}
}

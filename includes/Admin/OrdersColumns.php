<?php

namespace ZeusWeb\Multishop\Admin;

use ZeusWeb\Multishop\DB\Tables;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class OrdersColumns {
	public static function init(): void {
		// Classic posts-based orders screen
		add_filter( 'manage_edit-shop_order_columns', [ __CLASS__, 'add_columns' ], 20 );
		add_action( 'manage_shop_order_posts_custom_column', [ __CLASS__, 'render_column_post' ], 20, 2 );

		// WooCommerce HPOS/new orders screen (wc-orders)
		add_filter( 'manage_woocommerce_page_wc-orders_columns', [ __CLASS__, 'add_columns' ], 20 );
		add_action( 'manage_woocommerce_page_wc-orders_custom_column', [ __CLASS__, 'render_column_wc' ], 20, 2 );

		// Woo List Table convenience hooks
		add_filter( 'woocommerce_shop_order_list_table_columns', [ __CLASS__, 'add_columns' ], 20 );
		add_action( 'woocommerce_shop_order_list_table_custom_column', [ __CLASS__, 'render_column_wc' ], 20, 2 );
	}

	public static function add_columns( array $columns ): array {
		$new = [];
		foreach ( $columns as $key => $label ) {
			$new[ $key ] = $label;
			if ( $key === 'order_total' ) {
				$new['zw_ms_origin']  = __( 'Origin Site', 'zeusweb-multishop' );
				$new['zw_ms_segment'] = __( 'Segment', 'zeusweb-multishop' );
			}
		}
		if ( ! isset( $new['zw_ms_origin'] ) ) {
			$new['zw_ms_origin']  = __( 'Origin Site', 'zeusweb-multishop' );
			$new['zw_ms_segment'] = __( 'Segment', 'zeusweb-multishop' );
		}
		return $new;
	}

	public static function render_column_post( string $column, int $post_id ): void {
		if ( self::already_rendered( $column, (string) $post_id ) ) { return; }
		if ( $column === 'zw_ms_origin' ) {
			$order = function_exists( 'wc_get_order' ) ? wc_get_order( $post_id ) : null;
			$site_id = $order ? (string) $order->get_meta( '_zw_ms_remote_site_id' ) : '';
			echo esc_html( self::site_id_to_domain_or_local( $site_id ) );
			return;
		}
		if ( $column === 'zw_ms_segment' ) {
			$order = function_exists( 'wc_get_order' ) ? wc_get_order( $post_id ) : null;
			$segment = $order ? (string) $order->get_meta( '_zw_ms_remote_segment' ) : '';
			if ( $segment === '' ) { $segment = 'consumer'; }
			echo esc_html( ucfirst( $segment ) );
			return;
		}
	}

	public static function render_column_wc( string $column, $order ): void {
		if ( ! $order || ! method_exists( $order, 'get_id' ) ) { return; }
		$id = (string) $order->get_id();
		if ( self::already_rendered( $column, $id ) ) { return; }
		if ( $column === 'zw_ms_origin' ) {
			$site_id = (string) $order->get_meta( '_zw_ms_remote_site_id' );
			echo esc_html( self::site_id_to_domain_or_local( $site_id ) );
			return;
		}
		if ( $column === 'zw_ms_segment' ) {
			$segment = (string) $order->get_meta( '_zw_ms_remote_segment' );
			if ( $segment === '' ) { $segment = 'consumer'; }
			echo esc_html( ucfirst( $segment ) );
			return;
		}
	}

	private static function site_id_to_domain_or_local( string $site_id ): string {
		if ( $site_id === '' ) {
			$host = (string) wp_parse_url( home_url(), PHP_URL_HOST );
			return $host !== '' ? $host : 'local';
		}
		global $wpdb;
		$table = Tables::sites();
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT site_url FROM {$table} WHERE site_id = %s LIMIT 1", $site_id ) );
		$domain = '';
		if ( $row && isset( $row->site_url ) ) {
			$domain = (string) wp_parse_url( (string) $row->site_url, PHP_URL_HOST );
		}
		return $domain !== '' ? $domain : $site_id;
	}

	private static function already_rendered( string $column, string $id ): bool {
		static $seen = [];
		$key = $column . ':' . $id;
		if ( isset( $seen[ $key ] ) ) { return true; }
		$seen[ $key ] = true;
		return false;
	}
}

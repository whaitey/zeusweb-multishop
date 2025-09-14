<?php

namespace ZeusWeb\Multishop\Admin;

use ZeusWeb\Multishop\DB\Tables;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class OrdersColumns {
	public static function init(): void {
		add_filter( 'manage_edit-shop_order_columns', [ __CLASS__, 'add_columns' ], 20 );
		add_action( 'manage_shop_order_posts_custom_column', [ __CLASS__, 'render_column' ], 20, 2 );
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

	public static function render_column( string $column, int $post_id ): void {
		if ( $column === 'zw_ms_origin' ) {
			$order = function_exists( 'wc_get_order' ) ? wc_get_order( $post_id ) : null;
			$site_id = $order ? (string) $order->get_meta( '_zw_ms_remote_site_id' ) : '';
			if ( $site_id === '' ) {
				// Local order: show current site's domain
				$host = wp_parse_url( home_url(), PHP_URL_HOST );
				echo esc_html( (string) $host );
				return;
			}
			global $wpdb;
			$table = Tables::sites();
			$row = $wpdb->get_row( $wpdb->prepare( "SELECT site_url FROM {$table} WHERE site_id = %s LIMIT 1", $site_id ) );
			$domain = '';
			if ( $row && isset( $row->site_url ) ) {
				$domain = (string) wp_parse_url( (string) $row->site_url, PHP_URL_HOST );
			}
			echo esc_html( $domain !== '' ? $domain : $site_id );
			return;
		}
		if ( $column === 'zw_ms_segment' ) {
			$order = function_exists( 'wc_get_order' ) ? wc_get_order( $post_id ) : null;
			$segment = '';
			if ( $order ) {
				$seg = (string) $order->get_meta( '_zw_ms_remote_segment' );
				$segment = $seg !== '' ? $seg : ( \ZeusWeb\Multishop\Segments\Manager::is_business() ? 'business' : 'consumer' );
			}
			echo esc_html( ucfirst( $segment ) );
			return;
		}
	}
}

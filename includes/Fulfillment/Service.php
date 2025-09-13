<?php

namespace ZeusWeb\Multishop\Fulfillment;

use ZeusWeb\Multishop\DB\Tables;
use ZeusWeb\Multishop\Keys\Service as KeysService;
use ZeusWeb\Multishop\Logger\Logger;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Service {
	public static function fulfill_backorders_for_product( int $product_id ): void {
		global $wpdb;
		$table = Tables::backorders();
		$rows  = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} WHERE product_id = %d AND fulfilled_at IS NULL ORDER BY created_at ASC LIMIT 50", $product_id ) );
		if ( empty( $rows ) ) { return; }
		foreach ( $rows as $row ) {
			$items = [ [
				'product_id' => (int) $row->product_id,
				'variation_id' => $row->variation_id ? (int) $row->variation_id : 0,
				'quantity' => (int) $row->qty_pending,
			] ];
			$alloc = KeysService::allocate_for_items( (string) $row->site_id, (string) $row->remote_order_id, $items );
			$delivered = 0;
			foreach ( $alloc as $a ) { $delivered += count( $a['keys'] ?? [] ); }
			if ( $delivered > 0 ) {
				// TODO: send webhook to Secondary or local site to attach remaining keys & email customer.
				if ( $delivered >= (int) $row->qty_pending ) {
					$wpdb->update( $table, [ 'fulfilled_at' => current_time( 'mysql', 1 ), 'qty_pending' => 0 ], [ 'id' => (int) $row->id ], [ '%s', '%d' ], [ '%d' ] );
				} else {
					$wpdb->update( $table, [ 'qty_pending' => max( 0, (int) $row->qty_pending - $delivered ) ], [ 'id' => (int) $row->id ], [ '%d' ], [ '%d' ] );
				}
			}
		}
	}
}



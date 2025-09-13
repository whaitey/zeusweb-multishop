<?php

namespace ZeusWeb\Multishop\Backorders;

use ZeusWeb\Multishop\DB\Tables;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Repository {
	public static function create_or_increment( string $site_id, string $order_ref, int $product_id, ?int $variation_id, int $qty ): void {
		global $wpdb;
		$table = Tables::backorders();
		$wpdb->insert( $table, [
			'site_id' => $site_id,
			'remote_order_id' => $order_ref,
			'product_id' => $product_id,
			'variation_id' => $variation_id ?: null,
			'qty_pending' => $qty,
			'created_at' => current_time( 'mysql', 1 ),
		], [ '%s', '%s', '%d', '%d', '%d', '%s' ] );
	}
}



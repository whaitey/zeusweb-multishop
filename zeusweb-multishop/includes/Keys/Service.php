<?php

namespace ZeusWeb\Multishop\Keys;

use ZeusWeb\Multishop\Utils\Crypto;
use ZeusWeb\Multishop\Backorders\Repository as Backorders;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Service {
	public static function allocate_for_items( string $site_id, string $order_ref, array $items ): array {
		$results = [];
		foreach ( $items as $item ) {
			$product_id   = (int) $item['product_id'];
			$variation_id = (int) ( $item['variation_id'] ?? 0 );
			$quantity     = max( 0, (int) $item['quantity'] );
			if ( $quantity === 0 ) {
				continue;
			}
			$enc_keys = Repository::allocate_keys( $product_id, $variation_id ?: null, $quantity, $site_id, $order_ref );
			$got      = count( $enc_keys );
			$keys     = array_map( function ( $enc ) { return Crypto::decrypt( $enc ); }, $enc_keys );
			$pending  = max( 0, $quantity - $got );
			if ( $pending > 0 ) {
				Backorders::create_or_increment( $site_id, $order_ref, $product_id, $variation_id ?: null, $pending );
			}
			$results[] = [
				'product_id' => $product_id,
				'variation_id' => $variation_id ?: null,
				'keys' => $keys,
				'pending' => $pending,
			];
		}
		return $results;
	}
}



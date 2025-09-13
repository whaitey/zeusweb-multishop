<?php

namespace ZeusWeb\Multishop\Pricing;

use ZeusWeb\Multishop\Segments\Manager as SegmentManager;
use ZeusWeb\Multishop\Products\Meta as ProductMeta;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Resolver {
	public static function init(): void {
		// Simple products price
		add_filter( 'woocommerce_product_get_price', [ __CLASS__, 'filter_price' ], 20, 2 );
		add_filter( 'woocommerce_product_get_regular_price', [ __CLASS__, 'filter_price' ], 20, 2 );
	}

	public static function filter_price( $price, $product ) {
		try {
			if ( ! SegmentManager::is_business() ) {
				return $price;
			}
			$business_price = get_post_meta( $product->get_id(), ProductMeta::META_BUSINESS_PRICE, true );
			if ( $business_price !== '' && $business_price !== null ) {
				return $business_price;
			}
			return $price;
		} catch ( \Throwable $e ) {
			return $price;
		}
	}
}



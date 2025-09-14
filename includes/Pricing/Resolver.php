<?php

namespace ZeusWeb\Multishop\Pricing;

use ZeusWeb\Multishop\Segments\Manager as SegmentManager;
use ZeusWeb\Multishop\Products\Meta as ProductMeta;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Resolver {
	public static function init(): void {
		// Product price retrieval
		add_filter( 'woocommerce_product_get_price', [ __CLASS__, 'filter_price' ], 20, 2 );
		add_filter( 'woocommerce_product_get_regular_price', [ __CLASS__, 'filter_price' ], 20, 2 );
		add_filter( 'woocommerce_product_get_sale_price', [ __CLASS__, 'filter_price' ], 20, 2 );

		// Ensure cart line items reflect segment pricing
		add_action( 'woocommerce_before_calculate_totals', [ __CLASS__, 'adjust_cart_item_prices' ], 20 );
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

	public static function adjust_cart_item_prices( $cart ): void {
		try {
			if ( is_admin() && ! defined( 'DOING_AJAX' ) ) { return; }
			if ( ! $cart || ! SegmentManager::is_business() ) { return; }
			foreach ( $cart->get_cart() as $cart_item_key => $cart_item ) {
				if ( empty( $cart_item['data'] ) || ! is_object( $cart_item['data'] ) ) { continue; }
				$product = $cart_item['data'];
				$business_price = get_post_meta( $product->get_id(), ProductMeta::META_BUSINESS_PRICE, true );
				if ( $business_price !== '' && $business_price !== null ) {
					if ( method_exists( $product, 'set_price' ) ) {
						$product->set_price( (float) $business_price );
					}
				}
			}
		} catch ( \Throwable $e ) {
			// Silent fail to avoid breaking checkout
		}
	}
}



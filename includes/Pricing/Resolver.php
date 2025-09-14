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

		// Display consistency: price HTML and cart rows
		add_filter( 'woocommerce_get_price_html', [ __CLASS__, 'filter_price_html' ], 20, 2 );
		add_filter( 'woocommerce_cart_item_price', [ __CLASS__, 'filter_cart_item_price_html' ], 20, 3 );
		add_filter( 'woocommerce_cart_item_subtotal', [ __CLASS__, 'filter_cart_item_subtotal_html' ], 20, 3 );
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

	public static function filter_price_html( $price_html, $product ) {
		try {
			if ( ! SegmentManager::is_business() ) { return $price_html; }
			$business_price = get_post_meta( $product->get_id(), ProductMeta::META_BUSINESS_PRICE, true );
			if ( $business_price === '' || $business_price === null ) { return $price_html; }
			// Use Woo formatter for consistency
			if ( function_exists( 'wc_price' ) ) {
				return wc_price( (float) $business_price );
			}
			return $price_html;
		} catch ( \Throwable $e ) {
			return $price_html;
		}
	}

	public static function filter_cart_item_price_html( $price_html, $cart_item, $cart_item_key ) {
		try {
			if ( ! SegmentManager::is_business() ) { return $price_html; }
			$product_id = isset( $cart_item['product_id'] ) ? (int) $cart_item['product_id'] : 0;
			if ( ! $product_id ) { return $price_html; }
			$business_price = get_post_meta( $product_id, ProductMeta::META_BUSINESS_PRICE, true );
			if ( $business_price === '' || $business_price === null ) { return $price_html; }
			return function_exists( 'wc_price' ) ? wc_price( (float) $business_price ) : $price_html;
		} catch ( \Throwable $e ) {
			return $price_html;
		}
	}

	public static function filter_cart_item_subtotal_html( $subtotal_html, $cart_item, $cart_item_key ) {
		try {
			if ( ! SegmentManager::is_business() ) { return $subtotal_html; }
			$product_id = isset( $cart_item['product_id'] ) ? (int) $cart_item['product_id'] : 0;
			$qty = isset( $cart_item['quantity'] ) ? (int) $cart_item['quantity'] : 1;
			if ( ! $product_id || $qty <= 0 ) { return $subtotal_html; }
			$business_price = get_post_meta( $product_id, ProductMeta::META_BUSINESS_PRICE, true );
			if ( $business_price === '' || $business_price === null ) { return $subtotal_html; }
			$amount = (float) $business_price * max( 1, $qty );
			return function_exists( 'wc_price' ) ? wc_price( $amount ) : $subtotal_html;
		} catch ( \Throwable $e ) {
			return $subtotal_html;
		}
	}
}



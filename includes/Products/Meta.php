<?php

namespace ZeusWeb\Multishop\Products;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Meta {
	public const META_BUSINESS_PRICE = '_zw_ms_business_price';
    public const META_CUSTOM_EMAIL   = '_zw_ms_custom_email';

	public static function init(): void {
		// Removed business price field injection
		// add_action( 'woocommerce_product_options_pricing', [ __CLASS__, 'add_business_price_field' ] );
		add_action( 'woocommerce_process_product_meta', [ __CLASS__, 'save_product_meta' ], 10, 2 );
        // Custom email metabox removed
	}

	public static function add_business_price_field(): void {
		// No-op: business pricing removed
	}

	public static function save_product_meta( int $post_id, $post ): void {
		// Clean up legacy meta if form submits an empty value (or remove if present)
		if ( isset( $_POST[ self::META_BUSINESS_PRICE ] ) ) {
			delete_post_meta( $post_id, self::META_BUSINESS_PRICE );
		}
	}

}



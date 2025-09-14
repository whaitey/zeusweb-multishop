<?php

namespace ZeusWeb\Multishop\Products;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Meta {
	public const META_BUSINESS_PRICE = '_zw_ms_business_price';
	public const META_CUSTOM_EMAIL   = '_zw_ms_custom_email';

	public static function init(): void {
		add_action( 'woocommerce_product_options_pricing', [ __CLASS__, 'add_business_price_field' ] );
		add_action( 'woocommerce_process_product_meta', [ __CLASS__, 'save_product_meta' ], 10, 2 );
		add_action( 'add_meta_boxes', [ __CLASS__, 'add_custom_email_metabox' ] );
		add_action( 'save_post_product', [ __CLASS__, 'save_custom_email_metabox' ] );
	}

	public static function add_business_price_field(): void {
		woocommerce_wp_text_input( [
			'id'                => self::META_BUSINESS_PRICE,
			'label'             => __( 'Business price', 'zeusweb-multishop' ),
			'data_type'         => 'price',
			'desc_tip'          => true,
			'description'       => __( 'Price for Business segment (/uzleti). Leave empty to use regular price.', 'zeusweb-multishop' ),
			'wrapper_class'     => 'form-field-wide',
		] );
	}

	public static function save_product_meta( int $post_id, $post ): void {
		if ( isset( $_POST[ self::META_BUSINESS_PRICE ] ) ) {
			$price = wc_clean( wp_unslash( $_POST[ self::META_BUSINESS_PRICE ] ) );
			if ( $price === '' ) {
				delete_post_meta( $post_id, self::META_BUSINESS_PRICE );
			} else {
				update_post_meta( $post_id, self::META_BUSINESS_PRICE, wc_format_decimal( $price ) );
			}
		}
	}

	public static function add_custom_email_metabox(): void {
		add_meta_box(
			'zw-ms-custom-email',
			__( 'ZeusWeb: Custom purchase email', 'zeusweb-multishop' ),
			[ __CLASS__, 'render_custom_email_metabox' ],
			'product',
			'normal',
			'high'
		);
	}

	public static function render_custom_email_metabox( $post ): void {
		$value = get_post_meta( $post->ID, self::META_CUSTOM_EMAIL, true );
		echo '<textarea style="width:100%;min-height:180px" name="' . esc_attr( self::META_CUSTOM_EMAIL ) . '" id="' . esc_attr( self::META_CUSTOM_EMAIL ) . '">';
		echo esc_textarea( (string) $value );
		echo '</textarea>';
		echo '<p class="description">' . esc_html__( 'HTML allowed. This content will be sent with the order email for this product.', 'zeusweb-multishop' ) . '</p>';
		echo '<p class="description">' . esc_html__( 'Placeholders: {product_name}, {quantity}, {keys}, {keys_html}, {keys_raw}, {shortage_note}', 'zeusweb-multishop' ) . '</p>';
		wp_nonce_field( 'zw_ms_save_email', 'zw_ms_email_nonce' );
	}

	public static function save_custom_email_metabox( int $post_id ): void {
		if ( ! isset( $_POST['zw_ms_email_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['zw_ms_email_nonce'] ) ), 'zw_ms_save_email' ) ) {
			return;
		}
		if ( isset( $_POST[ self::META_CUSTOM_EMAIL ] ) ) {
			$content = wp_kses_post( wp_unslash( $_POST[ self::META_CUSTOM_EMAIL ] ) );
			if ( $content === '' ) {
				delete_post_meta( $post_id, self::META_CUSTOM_EMAIL );
			} else {
				update_post_meta( $post_id, self::META_CUSTOM_EMAIL, $content );
			}
		}
	}
}



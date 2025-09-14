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
		$value = (string) get_post_meta( $post->ID, self::META_CUSTOM_EMAIL, true );
		$editor_id = 'zw_ms_custom_email_editor_' . (int) $post->ID;
		$settings = [
			'textarea_name' => self::META_CUSTOM_EMAIL,
			'editor_height' => 240,
			'media_buttons' => false,
			'teeny' => false,
			'tinymce' => true,
		];
		if ( function_exists( 'wp_editor' ) ) {
			wp_editor( $value, $editor_id, $settings );
		} else {
			echo '<textarea style="width:100%;min-height:240px" name="' . esc_attr( self::META_CUSTOM_EMAIL ) . '">' . esc_textarea( $value ) . '</textarea>';
		}
		echo '<p class="description">' . esc_html__( 'This content is sent to the customer for this product. HTML allowed.', 'zeusweb-multishop' ) . '</p>';
		echo '<p class="description">' . esc_html__( 'Placeholders: {product_name}, {quantity}, {keys}, {keys_html}, {keys_raw}, {shortage_note}', 'zeusweb-multishop' ) . '</p>';
		// Quick placeholder buttons
		$tokens = [ '{product_name}', '{quantity}', '{keys}', '{keys_html}', '{keys_raw}', '{shortage_note}' ];
		echo '<p>';
		foreach ( $tokens as $t ) {
			echo '<button type="button" class="button zw-ms-insert-token" data-token="' . esc_attr( $t ) . '" data-target="' . esc_attr( $editor_id ) . '">' . esc_html( $t ) . '</button> ';
		}
		echo '</p>';
		echo '<script>(function(){function ins(t,id){try{if(window.tinyMCE&&tinyMCE.get(id)){tinyMCE.get(id).execCommand("mceInsertContent",false,t);}else{var ta=document.getElementById(id);if(ta){var s=ta.selectionStart,e=ta.selectionEnd,v=ta.value;ta.value=v.substring(0,s)+t+v.substring(e);}}}catch(e){}}
		var btns=document.querySelectorAll(".zw-ms-insert-token");btns.forEach(function(b){b.addEventListener("click",function(){ins(this.getAttribute("data-token"),this.getAttribute("data-target"));});});})();</script>';
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



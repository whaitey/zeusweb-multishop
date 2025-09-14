<?php

namespace ZeusWeb\Multishop\Rest;

use WP_REST_Server;
use WP_REST_Request;
use WP_REST_Response;
use ZeusWeb\Multishop\Plugin;
use ZeusWeb\Multishop\Logger\Logger;
use ZeusWeb\Multishop\Keys\Service as KeysService;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Routes {
	public static function init(): void {
		add_action( 'rest_api_init', [ __CLASS__, 'register_routes' ] );
	}

	public static function register_routes(): void {
		register_rest_route( 'zw-ms/v1', '/allocate-keys', [
			'methods'             => WP_REST_Server::CREATABLE,
			'permission_callback' => [ __CLASS__, 'verify_hmac' ],
			'callback'            => [ __CLASS__, 'allocate_keys' ],
		] );

		register_rest_route( 'zw-ms/v1', '/fulfillment-callback', [
			'methods'             => WP_REST_Server::CREATABLE,
			'permission_callback' => [ __CLASS__, 'verify_hmac' ],
			'callback'            => [ __CLASS__, 'fulfillment_callback' ],
		] );

		register_rest_route( 'zw-ms/v1', '/catalog', [
			'methods'             => WP_REST_Server::READABLE,
			'permission_callback' => [ __CLASS__, 'verify_hmac' ],
			'callback'            => [ __CLASS__, 'get_catalog' ],
		] );
	}

	public static function verify_hmac( WP_REST_Request $request ): bool {
		$plugin = Plugin::instance();
		$secret = $plugin->get_secret();
		$signature = (string) $request->get_header( 'x-zw-signature' );
		$timestamp = (string) $request->get_header( 'x-zw-timestamp' );
		$nonce     = (string) $request->get_header( 'x-zw-nonce' );
		$path      = $request->get_route();
		$method    = $request->get_method();
		$body      = $request->get_body();
		return HMAC::verify( $signature, $method, $path, $timestamp, $nonce, $body, $secret );
	}

	public static function allocate_keys( WP_REST_Request $request ) {
		$params  = $request->get_json_params();
		$site_id = sanitize_text_field( (string) ( $params['site_id'] ?? '' ) );
		$order_id = sanitize_text_field( (string) ( $params['order_id'] ?? '' ) );
		$items   = is_array( $params['items'] ?? null ) ? $params['items'] : [];
		$alloc   = KeysService::allocate_for_items( $site_id, $order_id, $items );
		Logger::instance()->log( 'info', 'Keys allocated', [ 'site_id' => $site_id, 'order_ref' => $order_id ] );
		return new WP_REST_Response( [ 'allocations' => $alloc ], 200 );
	}

	public static function fulfillment_callback( WP_REST_Request $request ) {
		Logger::instance()->log( 'info', 'Fulfillment callback', [ 'body' => $request->get_json_params() ] );
		return new WP_REST_Response( [ 'ok' => true ], 200 );
	}

	public static function get_catalog( WP_REST_Request $request ) {
		// Primary only
		if ( get_option( 'zw_ms_mode', 'primary' ) !== 'primary' ) {
			return new WP_REST_Response( [ 'error' => 'not_primary' ], 400 );
		}
		$args = [
			'post_type' => 'product',
			'post_status' => [ 'publish' ],
			'posts_per_page' => 200,
			'paged' => max( 1, absint( $request->get_param( 'page' ) ?: 1 ) ),
		];
		$q = new \WP_Query( $args );
		$items = [];
		while ( $q->have_posts() ) { $q->the_post();
			$pid = get_the_ID();
			$product = function_exists( 'wc_get_product' ) ? wc_get_product( $pid ) : null;
			if ( ! $product ) { continue; }
			$sku = (string) $product->get_sku();
			$type = method_exists( $product, 'get_type' ) ? (string) $product->get_type() : 'simple';
			$regular = (float) wc_get_price_to_display( $product );
			$business = (string) get_post_meta( $pid, \ZeusWeb\Multishop\Products\Meta::META_BUSINESS_PRICE, true );
			$custom_email = (string) get_post_meta( $pid, \ZeusWeb\Multishop\Products\Meta::META_CUSTOM_EMAIL, true );
			$image_id = get_post_thumbnail_id( $pid );
			$image_url = $image_id ? (string) wp_get_attachment_url( $image_id ) : '';
			$items[] = [
				'sku' => $sku,
				'title' => get_the_title( $pid ),
				'type' => $type,
				'price' => $regular,
				'business_price' => $business === '' ? null : (float) $business,
				'custom_email' => $custom_email,
				'image' => $image_url,
			];
		}
		wp_reset_postdata();
		return new WP_REST_Response( [ 'items' => $items, 'page' => (int) $q->get( 'paged' ), 'max_pages' => (int) $q->max_num_pages ], 200 );
	}
}



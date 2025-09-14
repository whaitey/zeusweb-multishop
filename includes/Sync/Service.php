<?php

namespace ZeusWeb\Multishop\Sync;

use ZeusWeb\Multishop\Logger\Logger;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Service {
	public static function init(): void {
		add_action( 'zw_ms_catalog_sync', [ __CLASS__, 'run' ] );
	}

	public static function schedule_if_needed(): void {
		if ( get_option( 'zw_ms_mode', 'primary' ) !== 'secondary' ) { return; }
		if ( ! wp_next_scheduled( 'zw_ms_catalog_sync' ) ) {
			wp_schedule_event( time() + 300, 'hourly', 'zw_ms_catalog_sync' );
		}
	}

	public static function run(): void {
		if ( get_option( 'zw_ms_mode', 'primary' ) !== 'secondary' ) { return; }
		$primary = (string) get_option( 'zw_ms_primary_url', '' );
		$primary_secret  = (string) get_option( 'zw_ms_primary_secret', '' );
		if ( ! $primary || ! $primary_secret ) {
			Logger::instance()->log( 'error', 'Catalog sync skipped: primary URL/secret missing' );
			return;
		}
		$path = '/zw-ms/v1/catalog';
		$method = 'GET';
		$timestamp = (string) time();
		$nonce = wp_generate_uuid4();
		$body = '';
		$signature = \ZeusWeb\Multishop\Rest\HMAC::sign( $method, $path, $timestamp, $nonce, $body, $primary_secret );
		$url = rtrim( $primary, '/' ) . '/wp-json' . $path;
		$args = [
			'headers' => [
				'X-ZW-Timestamp' => $timestamp,
				'X-ZW-Nonce' => $nonce,
				'X-ZW-Signature' => $signature,
				'Accept' => 'application/json',
			],
			'timeout' => 30,
		];
		$response = wp_remote_get( $url, $args );
		if ( is_wp_error( $response ) ) {
			Logger::instance()->log( 'error', 'Catalog sync HTTP error', [ 'error' => $response->get_error_message() ] );
			return;
		}
		$code = (int) wp_remote_retrieve_response_code( $response );
		$data = json_decode( wp_remote_retrieve_body( $response ), true );
		if ( $code !== 200 || ! is_array( $data ) || ! is_array( $data['items'] ?? null ) ) {
			Logger::instance()->log( 'error', 'Catalog sync invalid response', [ 'status' => $code ] );
			return;
		}
		self::upsert_items( $data['items'] );
	}

	private static function upsert_items( array $items ): void {
		$created = 0; $updated = 0; $skipped = 0;
		foreach ( $items as $row ) {
			$sku = trim( (string) ( $row['sku'] ?? '' ) );
			if ( $sku === '' ) { $skipped++; continue; }
			$title = (string) ( $row['title'] ?? $sku );
			$price = isset( $row['price'] ) ? (float) $row['price'] : 0;
			$business_price = isset( $row['business_price'] ) ? (float) $row['business_price'] : null;
			$custom_email = (string) ( $row['custom_email'] ?? '' );
			$image = (string) ( $row['image'] ?? '' );

			$product_id = wc_get_product_id_by_sku( $sku );
			$creating = false;
			if ( ! $product_id ) {
				$new_id = wp_insert_post( [ 'post_type' => 'product', 'post_status' => 'publish', 'post_title' => $title ] );
				if ( ! $new_id || is_wp_error( $new_id ) ) { $skipped++; continue; }
				$product_id = (int) $new_id;
				update_post_meta( $product_id, '_sku', $sku );
				$creating = true;
			}
			$product = wc_get_product( $product_id );
			if ( $product ) {
				$product->set_regular_price( (string) $price );
				$product->save();
			}
			if ( $business_price !== null ) {
				update_post_meta( $product_id, \ZeusWeb\Multishop\Products\Meta::META_BUSINESS_PRICE, (string) $business_price );
			} else {
				delete_post_meta( $product_id, \ZeusWeb\Multishop\Products\Meta::META_BUSINESS_PRICE );
			}
			update_post_meta( $product_id, \ZeusWeb\Multishop\Products\Meta::META_CUSTOM_EMAIL, $custom_email );

			if ( $image ) {
				$att = self::sideload_image( $image, $product_id );
				if ( $att ) { set_post_thumbnail( $product_id, $att ); }
			}

			if ( $creating ) { $created++; } else { $updated++; }
		}
		Logger::instance()->log( 'info', 'Catalog sync complete', [ 'created' => $created, 'updated' => $updated, 'skipped' => $skipped ] );
	}

	private static function sideload_image( string $url, int $post_id ): ?int {
		if ( ! function_exists( 'media_sideload_image' ) ) {
			require_once ABSPATH . 'wp-admin/includes/media.php';
			require_once ABSPATH . 'wp-admin/includes/file.php';
			require_once ABSPATH . 'wp-admin/includes/image.php';
		}
		$media = media_sideload_image( $url, $post_id, null, 'id' );
		if ( is_wp_error( $media ) ) { return null; }
		return (int) $media;
	}
}

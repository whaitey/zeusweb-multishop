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

		register_rest_route( 'zw-ms/v1', '/mirror-order', [
			'methods'             => WP_REST_Server::CREATABLE,
			'permission_callback' => [ __CLASS__, 'verify_hmac' ],
			'callback'            => [ __CLASS__, 'mirror_order' ],
		] );

		register_rest_route( 'zw-ms/v1', '/deliver-keys', [
			'methods'             => WP_REST_Server::CREATABLE,
			'permission_callback' => [ __CLASS__, 'verify_hmac_from_primary' ],
			'callback'            => [ __CLASS__, 'deliver_keys' ],
		] );

		register_rest_route( 'zw-ms/v1', '/payments-config', [
			'methods'             => WP_REST_Server::READABLE,
			'permission_callback' => [ __CLASS__, 'verify_hmac' ],
			'callback'            => [ __CLASS__, 'payments_config' ],
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

	public static function verify_hmac_from_primary( WP_REST_Request $request ): bool {
		// On Secondary, trust requests signed with Primary shared secret
		$primary_secret = (string) get_option( 'zw_ms_primary_secret', '' );
		if ( get_option( 'zw_ms_mode', 'primary' ) !== 'secondary' || $primary_secret === '' ) {
			return false;
		}
		$signature = (string) $request->get_header( 'x-zw-signature' );
		$timestamp = (string) $request->get_header( 'x-zw-timestamp' );
		$nonce     = (string) $request->get_header( 'x-zw-nonce' );
		$path      = $request->get_route();
		$method    = $request->get_method();
		$body      = $request->get_body();
		return HMAC::verify( $signature, $method, $path, $timestamp, $nonce, $body, $primary_secret );
	}

	public static function allocate_keys( WP_REST_Request $request ) {
		$params  = $request->get_json_params();
		$site_id = sanitize_text_field( (string) ( $params['site_id'] ?? '' ) );
		$callback_url = esc_url_raw( (string) ( $params['callback_url'] ?? '' ) );
		$site_code = sanitize_text_field( (string) ( $params['site_code'] ?? '' ) );
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
		if ( get_option( 'zw_ms_mode', 'primary' ) !== 'primary' ) {
			return new WP_REST_response( [ 'error' => 'not_primary' ], 400 );
		}
		$args = [ 'post_type' => 'product', 'post_status' => [ 'publish' ], 'posts_per_page' => 200, 'paged' => max( 1, absint( $request->get_param( 'page' ) ?: 1 ) ) ];
		$q = new \WP_Query( $args );
		$items = [];
		while ( $q->have_posts() ) { $q->the_post();
			$pid = get_the_ID();
			$product = function_exists( 'wc_get_product' ) ? wc_get_product( $pid ) : null;
			if ( ! $product ) { continue; }
			$sku = (string) $product->get_sku();
			$type = method_exists( $product, 'get_type' ) ? (string) $product->get_type() : 'simple';
			$regular = (float) wc_get_price_to_display( $product );
			$custom_email = (string) get_post_meta( $pid, \ZeusWeb\Multishop\Products\Meta::META_CUSTOM_EMAIL, true );
			$image_id = get_post_thumbnail_id( $pid );
			$image_url = $image_id ? (string) wp_get_attachment_url( $image_id ) : '';
			$items[] = [ 'sku' => $sku, 'title' => get_the_title( $pid ), 'type' => $type, 'price' => $regular, 'custom_email' => $custom_email, 'image' => $image_url ];
		}
		wp_reset_postdata();
		return new WP_REST_Response( [ 'items' => $items, 'page' => (int) $q->get( 'paged' ), 'max_pages' => (int) $q->max_num_pages ], 200 );
	}

	public static function mirror_order( WP_REST_Request $request ) {
		if ( get_option( 'zw_ms_mode', 'primary' ) !== 'primary' ) {
			return new WP_REST_Response( [ 'error' => 'not_primary' ], 400 );
		}
		$params = $request->get_json_params();
		$site_id = sanitize_text_field( (string) ( $params['site_id'] ?? '' ) );
		$remote_order_id = sanitize_text_field( (string) ( $params['order_id'] ?? '' ) );
		$remote_order_number = sanitize_text_field( (string) ( $params['remote_order_number'] ?? '' ) );
		$segment = sanitize_text_field( (string) ( $params['customer_segment'] ?? '' ) );
		$email = sanitize_email( (string) ( $params['customer_email'] ?? '' ) );
		$billing = is_array( $params['billing'] ?? null ) ? array_map( 'sanitize_text_field', $params['billing'] ) : [];
		$shipping = is_array( $params['shipping'] ?? null ) ? array_map( 'sanitize_text_field', $params['shipping'] ) : [];
		$customer_note = sanitize_text_field( (string) ( $params['customer_note'] ?? '' ) );
		$items_raw = is_array( $params['items'] ?? null ) ? $params['items'] : [];
		try {
			$order = wc_create_order();
			if ( $email ) { $order->set_billing_email( $email ); }
			if ( ! empty( $billing ) && method_exists( $order, 'set_address' ) ) {
				$order->set_address( $billing, 'billing' );
			}
			if ( ! empty( $shipping ) && method_exists( $order, 'set_address' ) ) {
				$order->set_address( $shipping, 'shipping' );
			}
			if ( $customer_note !== '' && method_exists( $order, 'set_customer_note' ) ) {
				$order->set_customer_note( $customer_note );
			}
			$order->update_meta_data( '_zw_ms_remote_site_id', $site_id );
			$order->update_meta_data( '_zw_ms_remote_order_id', $remote_order_id );
			if ( $remote_order_number !== '' ) { $order->update_meta_data( '_zw_ms_remote_order_number', $remote_order_number ); }
			$order->update_meta_data( '_zw_ms_remote_segment', in_array( $segment, [ 'consumer', 'business' ], true ) ? $segment : 'consumer' );
			$order->update_meta_data( '_zw_ms_origin_site_code', $site_code !== '' ? $site_code : (string) get_option( 'zw_ms_site_code', '1' ) );
			$order->update_meta_data( '_zw_ms_mirrored', 'yes' );
			// Email handling removed; each site manages emails independently

			$alloc_items = [];
			foreach ( $items_raw as $it ) {
				$sku = sanitize_text_field( (string) ( $it['sku'] ?? '' ) );
				$quantity = max( 0, (int) ( $it['quantity'] ?? 0 ) );
				if ( $sku === '' || $quantity <= 0 ) { continue; }
				$product_id = wc_get_product_id_by_sku( $sku );
				if ( ! $product_id ) { continue; }
				$prod = wc_get_product( $product_id );
				if ( $prod ) {
					$item_id = $order->add_product( $prod, $quantity );
					// Always use regular product prices; no business pricing override
					$alloc_items[] = [ 'product_id' => (int) $product_id, 'variation_id' => 0, 'quantity' => $quantity ];
				}
			}
			// Ensure totals are calculated so order appears with correct total
			if ( method_exists( $order, 'calculate_totals' ) ) {
				$order->calculate_totals();
			}
			// Optional: mark payment method for traceability
			if ( method_exists( $order, 'set_payment_method_title' ) ) {
				$order->set_payment_method_title( 'Mirrored from Secondary' );
			}
            // Defer status update until after keys are attached so emails include keys
            $order->save();

			$alloc = KeysService::allocate_for_items( $site_id, $remote_order_id, $alloc_items );
			$shortage_msg = (string) get_option( 'zw_ms_shortage_message', '' );
			foreach ( $alloc as $a ) {
				$pid = (int) ( $a['product_id'] ?? 0 );
				$keys = is_array( $a['keys'] ?? null ) ? $a['keys'] : [];
				$pending = (int) ( $a['pending'] ?? 0 );
				if ( $pid <= 0 ) { continue; }
				foreach ( $order->get_items() as $item_id => $item ) {
					if ( (int) $item->get_product_id() !== $pid ) { continue; }
					if ( ! empty( $keys ) ) {
						wc_add_order_item_meta( $item_id, '_zw_ms_keys', implode( "\n", array_map( 'sanitize_text_field', $keys ) ) );
					}
					if ( $pending > 0 && $shortage_msg ) {
						wc_add_order_item_meta( $item_id, '_zw_ms_shortage', $shortage_msg );
					}
				}
			}
            $order->save();
            // Now set status to processing so default Woo email includes attached keys
            $order->set_status( 'processing' );
            $order->save();

            // If this order originated from a Secondary (site_id != this site's ID), POST keys back
			$local_site_id = (string) get_option( 'zw_ms_site_id' );
			if ( $site_id !== '' && $local_site_id !== '' && $site_id !== $local_site_id ) {
                self::post_keys_back_to_secondary( $site_id, $alloc, $remote_order_id, $callback_url );
			}

			Logger::instance()->log( 'info', 'Order mirrored (emails handled by origin site)', [ 'remote_order_id' => $remote_order_id, 'site_id' => $site_id ] );
			return new WP_REST_Response( [ 'allocations' => $alloc, 'order_id' => $order->get_id(), 'order_number' => $order->get_order_number() ], 200 );
		} catch ( \Throwable $e ) {
			Logger::instance()->log( 'error', 'Mirror order failed', [ 'error' => $e->getMessage(), 'remote_order_id' => $remote_order_id ] );
			return new WP_REST_Response( [ 'error' => 'mirror_failed' ], 500 );
		}
	}

	public static function payments_config( WP_REST_Request $request ) {
		if ( get_option( 'zw_ms_mode', 'primary' ) !== 'primary' ) {
			return new WP_REST_Response( [ 'error' => 'not_primary' ], 400 );
		}
		$site_id = sanitize_text_field( (string) $request->get_param( 'site_id' ) );
		$segment = sanitize_text_field( (string) $request->get_param( 'segment' ) );
		$matrix = get_option( 'zw_ms_gateways_matrix', [] );
		if ( ! is_array( $matrix ) ) { $matrix = []; }
		$allowed = [];
		if ( $site_id !== '' && isset( $matrix[ $site_id ] ) && isset( $matrix[ $site_id ][ $segment ] ) && is_array( $matrix[ $site_id ][ $segment ] ) ) {
			$raw = array_values( array_map( 'strval', $matrix[ $site_id ][ $segment ] ) );
			// Normalize any legacy stripe_* entries into a single 'stripe'
			$has_stripe_child = false; $out = [];
			foreach ( $raw as $id ) {
				if ( strpos( (string) $id, 'stripe_' ) === 0 ) { $has_stripe_child = true; continue; }
				$out[] = $id;
			}
			if ( $has_stripe_child && ! in_array( 'stripe', $out, true ) ) { $out[] = 'stripe'; }
			$allowed = array_values( array_unique( $out ) );
		}
		// Fallback: if requested site_id has no mapping but only one site exists in matrix, use that mapping
		if ( empty( $allowed ) && is_array( $matrix ) && count( $matrix ) === 1 ) {
			$only = array_values( $matrix )[0];
			if ( is_array( $only ) && is_array( $only[ $segment ] ?? null ) ) {
				$raw = array_values( array_map( 'strval', $only[ $segment ] ) );
				$has_stripe_child = false; $out = [];
				foreach ( $raw as $id ) {
					if ( strpos( (string) $id, 'stripe_' ) === 0 ) { $has_stripe_child = true; continue; }
					$out[] = $id;
				}
				if ( $has_stripe_child && ! in_array( 'stripe', $out, true ) ) { $out[] = 'stripe'; }
				$allowed = array_values( array_unique( $out ) );
				Logger::instance()->log( 'info', 'payments-config using single-site fallback', [ 'requested_site' => $site_id, 'segment' => $segment, 'allowed' => $allowed ] );
			}
		}
		Logger::instance()->log( 'info', 'payments-config response', [ 'site_id' => $site_id, 'segment' => $segment, 'allowed' => $allowed ] );
		return new WP_REST_Response( [ 'allowed' => $allowed ], 200 );
	}

    private static function post_keys_back_to_secondary( string $secondary_site_id, array $allocations, string $remote_order_id, ?string $callback_url = '' ): void {
		try {
            $callback_url  = is_string( $callback_url ) ? $callback_url : '';
            $secondary_url = $callback_url !== '' ? $callback_url : (string) get_option( 'zw_ms_secondary_callback_url_' . $secondary_site_id, '' );
            $secret = (string) get_option( 'zw_ms_primary_secret', '' );
            if ( $secondary_url === '' || $secret === '' ) {
                \ZeusWeb\Multishop\Logger\Logger::instance()->log( 'error', 'deliver-keys: missing callback URL or secret', [ 'site_id' => $secondary_site_id, 'has_url' => $secondary_url !== '', 'has_secret' => $secret !== '' ] );
                return;
            }
			$path = '/zw-ms/v1/deliver-keys';
            $url  = rtrim( $secondary_url, '/' ) . '/wp-json' . $path;
			$body_arr = [ 'remote_order_id' => $remote_order_id, 'allocations' => $allocations ];
			$body = wp_json_encode( $body_arr );
			$method = 'POST';
			$timestamp = (string) time();
			$nonce = wp_generate_uuid4();
			$signature = HMAC::sign( $method, $path, $timestamp, $nonce, $body, $secret );
			$args = [
				'headers' => [
					'Content-Type'  => 'application/json',
					'X-ZW-Timestamp' => $timestamp,
					'X-ZW-Nonce'     => $nonce,
					'X-ZW-Signature' => $signature,
					'Accept'         => 'application/json',
				],
				'body'    => $body,
				'timeout' => 20,
			];
			$response = wp_remote_post( $url, $args );
            if ( is_wp_error( $response ) ) {
                \ZeusWeb\Multishop\Logger\Logger::instance()->log( 'error', 'deliver-keys: post failed', [ 'url' => $url, 'error' => $response->get_error_message() ] );
                return;
            }
            $code = (int) wp_remote_retrieve_response_code( $response );
            if ( $code !== 200 ) {
                \ZeusWeb\Multishop\Logger\Logger::instance()->log( 'error', 'deliver-keys: non-200 response', [ 'url' => $url, 'status' => $code, 'body' => wp_remote_retrieve_body( $response ) ] );
            } else {
                \ZeusWeb\Multishop\Logger\Logger::instance()->log( 'info', 'deliver-keys: success', [ 'url' => $url, 'remote_order_id' => $remote_order_id, 'alloc_count' => count( $allocations ) ] );
            }
		} catch ( \Throwable $e ) {
			// swallow; logging not available here
		}
	}

	public static function deliver_keys( WP_REST_Request $request ) {
        if ( get_option( 'zw_ms_mode', 'primary' ) !== 'secondary' ) {
			return new WP_REST_Response( [ 'error' => 'not_secondary' ], 400 );
		}
		$params = $request->get_json_params();
		$remote_order_id = sanitize_text_field( (string) ( $params['remote_order_id'] ?? '' ) );
		$allocations = is_array( $params['allocations'] ?? null ) ? $params['allocations'] : [];
		if ( $remote_order_id === '' || empty( $allocations ) ) {
			return new WP_REST_Response( [ 'error' => 'invalid' ], 400 );
		}
        \ZeusWeb\Multishop\Logger\Logger::instance()->log( 'info', 'deliver-keys received', [ 'remote_order_id' => $remote_order_id, 'alloc_count' => count( $allocations ) ] );
		$order = wc_get_order( (int) $remote_order_id );
		if ( ! $order ) {
			return new WP_REST_Response( [ 'error' => 'order_not_found' ], 404 );
		}
		// Attach keys
		$shortage = (string) get_option( 'zw_ms_shortage_message', '' );
		foreach ( $allocations as $alloc ) {
			$product_id = (int) ( $alloc['product_id'] ?? 0 );
			$keys       = is_array( $alloc['keys'] ?? null ) ? $alloc['keys'] : [];
			$pending    = (int) ( $alloc['pending'] ?? 0 );
			if ( $product_id <= 0 ) { continue; }
			foreach ( $order->get_items() as $item_id => $item ) {
				if ( (int) $item->get_product_id() !== $product_id ) { continue; }
				if ( ! empty( $keys ) ) {
					wc_add_order_item_meta( $item_id, '_zw_ms_keys', implode( "\n", array_map( 'sanitize_text_field', $keys ) ) );
				}
				if ( $pending > 0 && $shortage ) {
					wc_add_order_item_meta( $item_id, '_zw_ms_shortage', $shortage );
				}
			}
		}
		$order->save();
		// If all non-bundle items have keys, trigger emails on this Secondary
		$all_have_keys = true;
		foreach ( $order->get_items() as $item_id => $item ) {
			$product = $item->get_product();
			$is_bundle_container = $product && method_exists( $product, 'is_type' ) && $product->is_type( 'bundle' );
			if ( $is_bundle_container ) { continue; }
			$val = (string) wc_get_order_item_meta( $item_id, '_zw_ms_keys', true );
			if ( $val === '' ) { $all_have_keys = false; break; }
		}
		if ( $all_have_keys ) {
			// Trigger only custom keys email, auto-complete, then send Completed Woo email
			try {
				$order->add_order_note( 'Keys delivered from Primary; triggering customer emails.' );
				$custom_sent = false;
				if ( \ZeusWeb\Multishop\Emails\CustomSender::should_send_custom_email_now( $order ) ) {
					\ZeusWeb\Multishop\Emails\CustomSender::send_order_keys_email( $order );
					$custom_sent = true;
				}
				if ( method_exists( $order, 'update_status' ) ) {
					$order->update_status( 'completed', 'All keys delivered. Auto-completed by Multishop.' );
				}
				// Only trigger Completed Woo email on the origin (Secondary)
				$emails = function_exists( 'WC' ) && WC()->mailer() ? WC()->mailer()->get_emails() : [];
				$completed_triggered = false;
				foreach ( $emails as $email ) {
					if ( ! method_exists( $email, 'is_enabled' ) || ! $email->is_enabled() ) { continue; }
					if ( $email instanceof \WC_Email_Customer_Completed_Order ) { $email->trigger( $order->get_id() ); $completed_triggered = true; }
				}
				if ( ! $completed_triggered && ! $custom_sent ) {
					\ZeusWeb\Multishop\Emails\CustomSender::send_order_keys_email( $order );
				}
			} catch ( \Throwable $e ) { }
		}
		return new WP_REST_Response( [ 'ok' => true ], 200 );
	}
}



<?php

namespace ZeusWeb\Multishop\Fulfillment;

use ZeusWeb\Multishop\DB\Tables;
use ZeusWeb\Multishop\Keys\Service as KeysService;
use ZeusWeb\Multishop\Logger\Logger;
use ZeusWeb\Multishop\Emails\CustomSender;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Service {
	public static function fulfill_backorders_for_product( int $product_id ): void {
		global $wpdb;
		$table = Tables::backorders();
		$rows  = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} WHERE product_id = %d AND fulfilled_at IS NULL ORDER BY created_at ASC LIMIT 50", $product_id ) );
		if ( empty( $rows ) ) { return; }
		foreach ( $rows as $row ) {
			$items = [ [
				'product_id' => (int) $row->product_id,
				'variation_id' => $row->variation_id ? (int) $row->variation_id : 0,
				'quantity' => (int) $row->qty_pending,
			] ];
			$alloc = KeysService::allocate_for_items( (string) $row->site_id, (string) $row->remote_order_id, $items );
			$delivered = 0;
			$keys_by_product = [];
			foreach ( $alloc as $a ) {
				$keys = is_array( $a['keys'] ?? null ) ? $a['keys'] : [];
				$delivered += count( $keys );
				$pid = (int) ( $a['product_id'] ?? 0 );
				if ( $pid && ! empty( $keys ) ) {
					$keys_by_product[ $pid ] = $keys;
				}
			}
            if ( $delivered > 0 ) {
                // If this backorder belongs to our Primary site, attach keys to the order and email.
                if ( (string) $row->site_id === (string) get_option( 'zw_ms_site_id' ) ) {
					$order = wc_get_order( (int) $row->remote_order_id );
					if ( $order ) {
						foreach ( $order->get_items() as $item_id => $item ) {
							$pid = (int) $item->get_product_id();
							if ( isset( $keys_by_product[ $pid ] ) ) {
								$existing = (string) wc_get_order_item_meta( $item_id, '_zw_ms_keys', true );
								$new_keys = implode( "\n", array_map( 'sanitize_text_field', $keys_by_product[ $pid ] ) );
								$combined = trim( $existing ) !== '' ? ( $existing . "\n" . $new_keys ) : $new_keys;
								wc_update_order_item_meta( $item_id, '_zw_ms_keys', $combined );
								// Reduce shortage if present
								$shortage = (string) wc_get_order_item_meta( $item_id, '_zw_ms_shortage', true );
								if ( $shortage !== '' ) {
									wc_delete_order_item_meta( $item_id, '_zw_ms_shortage' );
								}
							}
						}
						$order->save();
						// Gate: only send when all non-bundle items have keys
						$all_have_keys = true;
						foreach ( $order->get_items() as $iid => $it ) {
							$prod = $it->get_product();
							$is_bundle_container = $prod && method_exists( $prod, 'is_type' ) && $prod->is_type( 'bundle' );
							if ( $is_bundle_container ) { continue; }
							$kv = (string) wc_get_order_item_meta( $iid, '_zw_ms_keys', true );
							if ( $kv === '' ) { $all_have_keys = false; break; }
						}
						if ( $all_have_keys ) {
							try {
								CustomSender::send_order_keys_email( $order );
								Logger::instance()->log( 'info', 'Backorder fulfillment email sent', [ 'order_id' => $order->get_id(), 'product_id' => (int) $row->product_id, 'delivered' => $delivered ] );
							} catch ( \Throwable $e ) {
								Logger::instance()->log( 'error', 'Backorder fulfillment email failed', [ 'order_id' => (int) $row->remote_order_id, 'error' => $e->getMessage() ] );
							}
						} else {
							Logger::instance()->log( 'info', 'Skipping backorder email (not all items have keys yet)', [ 'order_id' => (int) $row->remote_order_id ] );
						}
					}
                } else {
                    // Secondary-origin order: POST keys to Secondary to trigger emails there
                    $alloc = [];
                    foreach ( $keys_by_product as $pid => $keys ) {
                        $alloc[] = [ 'product_id' => (int) $pid, 'keys' => array_values( array_map( 'sanitize_text_field', $keys ) ), 'pending' => 0 ];
                    }
                    // Reuse Routes helper to post back
                    if ( class_exists( '\\ZeusWeb\\Multishop\\Rest\\Routes' ) ) {
                        \ZeusWeb\Multishop\Rest\Routes::deliver_keys( new \WP_REST_Request() ); // placeholder to ensure class loads
                    }
                    // Build and post
                    $secondary_url = (string) get_option( 'zw_ms_secondary_callback_url_' . (string) $row->site_id, '' );
                    $primary_url   = (string) get_option( 'zw_ms_primary_url', '' );
                    $base = $secondary_url !== '' ? $secondary_url : $primary_url;
                    $secret = (string) get_option( 'zw_ms_primary_secret', '' );
                    if ( $base && $secret ) {
                        $path = '/zw-ms/v1/deliver-keys';
                        $url  = rtrim( $base, '/' ) . '/wp-json' . $path;
                        $body_arr = [ 'remote_order_id' => (string) $row->remote_order_id, 'allocations' => $alloc ];
                        $body = wp_json_encode( $body_arr );
                        $method = 'POST';
                        $timestamp = (string) time();
                        $nonce = wp_generate_uuid4();
                        $sig = \ZeusWeb\Multishop\Rest\HMAC::sign( $method, $path, $timestamp, $nonce, $body, $secret );
                        $args = [ 'headers' => [ 'Content-Type' => 'application/json', 'X-ZW-Timestamp' => $timestamp, 'X-ZW-Nonce' => $nonce, 'X-ZW-Signature' => $sig, 'Accept' => 'application/json' ], 'body' => $body, 'timeout' => 20 ];
                        wp_remote_post( $url, $args );
                    }
                }
				// Update backorder row
				if ( $delivered >= (int) $row->qty_pending ) {
					$wpdb->update( $table, [ 'fulfilled_at' => current_time( 'mysql', 1 ), 'qty_pending' => 0 ], [ 'id' => (int) $row->id ], [ '%s', '%d' ], [ '%d' ] );
				} else {
					$wpdb->update( $table, [ 'qty_pending' => max( 0, (int) $row->qty_pending - $delivered ) ], [ 'id' => (int) $row->id ], [ '%d' ], [ '%d' ] );
				}
			}
		}
	}
}



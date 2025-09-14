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
				// If this backorder belongs to our Primary site, attach the new keys to the order items and email.
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
						try {
							CustomSender::send_order_keys_email( $order );
							Logger::instance()->log( 'info', 'Backorder fulfillment email sent', [ 'order_id' => $order->get_id(), 'product_id' => (int) $row->product_id, 'delivered' => $delivered ] );
						} catch ( \Throwable $e ) {
							Logger::instance()->log( 'error', 'Backorder fulfillment email failed', [ 'order_id' => (int) $row->remote_order_id, 'error' => $e->getMessage() ] );
						}
					}
				} else {
					// Secondary-origin order: find mirrored Primary order by remote identifiers and notify customer from Primary
					$orders = function_exists( 'wc_get_orders' ) ? wc_get_orders( [
						'type'      => 'shop_order',
						'limit'     => 1,
						'meta_query'=> [
							[ 'key' => '_zw_ms_remote_site_id', 'value' => (string) $row->site_id, 'compare' => '=' ],
							[ 'key' => '_zw_ms_remote_order_id', 'value' => (string) $row->remote_order_id, 'compare' => '=' ],
						],
					] ) : [];
					$order = is_array( $orders ) && ! empty( $orders ) ? $orders[0] : null;
					if ( $order ) {
						foreach ( $order->get_items() as $item_id => $item ) {
							$pid = (int) $item->get_product_id();
							if ( isset( $keys_by_product[ $pid ] ) ) {
								$existing = (string) wc_get_order_item_meta( $item_id, '_zw_ms_keys', true );
								$new_keys = implode( "\n", array_map( 'sanitize_text_field', $keys_by_product[ $pid ] ) );
								$combined = trim( $existing ) !== '' ? ( $existing . "\n" . $new_keys ) : $new_keys;
								wc_update_order_item_meta( $item_id, '_zw_ms_keys', $combined );
								$shortage = (string) wc_get_order_item_meta( $item_id, '_zw_ms_shortage', true );
								if ( $shortage !== '' ) {
									wc_delete_order_item_meta( $item_id, '_zw_ms_shortage' );
								}
							}
						}
						$order->save();
						try {
							CustomSender::send_order_keys_email( $order );
							Logger::instance()->log( 'info', 'Backorder fulfillment email sent (mirrored order)', [ 'order_id' => $order->get_id(), 'remote_order_id' => (string) $row->remote_order_id, 'site_id' => (string) $row->site_id, 'product_id' => (int) $row->product_id, 'delivered' => $delivered ] );
						} catch ( \Throwable $e ) {
							Logger::instance()->log( 'error', 'Backorder fulfillment email failed (mirrored order)', [ 'remote_order_id' => (string) $row->remote_order_id, 'error' => $e->getMessage() ] );
						}
					} else {
						Logger::instance()->log( 'warning', 'Mirrored order not found for backorder fulfillment', [ 'remote_order_id' => (string) $row->remote_order_id, 'site_id' => (string) $row->site_id ] );
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



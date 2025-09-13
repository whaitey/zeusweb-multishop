<?php

namespace ZeusWeb\Multishop\Keys;

use ZeusWeb\Multishop\DB\Tables;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Repository {
	public static function allocate_keys( int $product_id, ?int $variation_id, int $quantity, string $site_id, string $order_ref ): array {
		global $wpdb;
		$table = Tables::keys();
		$variation_clause = $variation_id ? $wpdb->prepare( 'variation_id = %d', $variation_id ) : 'variation_id IS NULL';
		$ids = $wpdb->get_col( $wpdb->prepare(
			"SELECT id FROM {$table} WHERE product_id = %d AND {$variation_clause} AND status = 'available' ORDER BY id ASC LIMIT %d",
			$product_id,
			$quantity
		) );
		if ( empty( $ids ) ) {
			return [];
		}
		$id_list = implode( ',', array_map( 'intval', $ids ) );
		$assigned_at = gmdate( 'Y-m-d H:i:s' );
		$wpdb->query( $wpdb->prepare( "UPDATE {$table} SET status = 'assigned', assigned_order_id = %s, assigned_site_id = %s, assigned_at = %s WHERE id IN ({$id_list})", $order_ref, $site_id, $assigned_at ) );
		$rows = $wpdb->get_results( "SELECT key_enc FROM {$table} WHERE id IN ({$id_list})" );
		$keys = [];
		foreach ( $rows as $row ) {
			$keys[] = $row->key_enc; // keep encrypted here; decryption done by service
		}
		return $keys;
	}

	public static function insert_keys( int $product_id, ?int $variation_id, array $plaintext_keys, callable $encryptor ): int {
		global $wpdb;
		$table = Tables::keys();
		$inserted = 0;
		foreach ( $plaintext_keys as $plain ) {
			$enc = call_user_func( $encryptor, $plain );
			$wpdb->insert( $table, [
				'product_id' => $product_id,
				'variation_id' => $variation_id ?: null,
				'key_enc' => $enc,
				'status' => 'available',
				'created_at' => current_time( 'mysql', 1 ),
			], [ '%d', '%d', '%s', '%s', '%s' ] );
			$inserted++;
		}
		return $inserted;
	}

	public static function update_available_key( int $id, string $new_encrypted ): bool {
		global $wpdb;
		$table = Tables::keys();
		$updated = $wpdb->update( $table, [ 'key_enc' => $new_encrypted ], [ 'id' => $id, 'status' => 'available' ], [ '%s' ], [ '%d', '%s' ] );
		return ( $updated !== false );
	}

	public static function delete_available_key( int $id ): bool {
		global $wpdb;
		$table = Tables::keys();
		$deleted = $wpdb->delete( $table, [ 'id' => $id, 'status' => 'available' ], [ '%d', '%s' ] );
		return ( $deleted !== false );
	}
}



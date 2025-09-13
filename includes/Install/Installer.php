<?php

namespace ZeusWeb\Multishop\Install;

use wpdb;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Installer {
	public const DB_VERSION       = '2';
	public const DB_VERSION_OPTION = 'zw_ms_db_version';

	public static function maybe_install(): void {
		$current = get_option( self::DB_VERSION_OPTION );
		if ( (string) $current === (string) self::DB_VERSION ) {
			return;
		}
		self::create_tables();
		update_option( self::DB_VERSION_OPTION, self::DB_VERSION );
	}

	private static function create_tables(): void {
		global $wpdb;
		/** @var wpdb $wpdb */
		$charset_collate = $wpdb->get_charset_collate();
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$keys_table       = $wpdb->prefix . 'zw_ms_keys';
		$backorders_table = $wpdb->prefix . 'zw_ms_backorders';
		$sites_table      = $wpdb->prefix . 'zw_ms_sites';
		$orders_table     = $wpdb->prefix . 'zw_ms_orders';
		$logs_table       = $wpdb->prefix . 'zw_ms_logs';

		$sql = [];

		$sql[] = "CREATE TABLE {$keys_table} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			product_id BIGINT UNSIGNED NOT NULL,
			variation_id BIGINT UNSIGNED NULL,
			key_enc LONGTEXT NOT NULL,
			status VARCHAR(16) NOT NULL DEFAULT 'available',
			assigned_order_id VARCHAR(64) NULL,
			assigned_site_id VARCHAR(64) NULL,
			assigned_at DATETIME NULL,
			created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
			PRIMARY KEY  (id),
			KEY idx_product_status (product_id, status),
			KEY idx_status (status)
		) {$charset_collate};";

		$sql[] = "CREATE TABLE {$backorders_table} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			site_id VARCHAR(64) NOT NULL,
			remote_order_id VARCHAR(64) NOT NULL,
			product_id BIGINT UNSIGNED NOT NULL,
			variation_id BIGINT UNSIGNED NULL,
			qty_pending INT UNSIGNED NOT NULL,
			created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
			fulfilled_at DATETIME NULL,
			PRIMARY KEY (id),
			KEY idx_product (product_id),
			KEY idx_site (site_id)
		) {$charset_collate};";

		$sql[] = "CREATE TABLE {$sites_table} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			site_id VARCHAR(64) NOT NULL,
			site_url VARCHAR(255) NOT NULL,
			api_key VARCHAR(128) NOT NULL,
			status VARCHAR(32) NOT NULL DEFAULT 'active',
			last_heartbeat DATETIME NULL,
			created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
			UNIQUE KEY uniq_site_id (site_id),
			PRIMARY KEY (id)
		) {$charset_collate};";

		$sql[] = "CREATE TABLE {$logs_table} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			timestamp DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
			level VARCHAR(16) NOT NULL,
			message TEXT NOT NULL,
			context LONGTEXT NULL,
			site_id VARCHAR(64) NULL,
			order_ref VARCHAR(64) NULL,
			PRIMARY KEY (id),
			KEY idx_level (level),
			KEY idx_site (site_id)
		) {$charset_collate};";

		$sql[] = "CREATE TABLE {$orders_table} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			site_id VARCHAR(64) NOT NULL,
			remote_order_id VARCHAR(64) NOT NULL,
			customer_segment VARCHAR(16) NOT NULL,
			status VARCHAR(32) NOT NULL,
			total DECIMAL(18,6) NOT NULL DEFAULT 0,
			currency VARCHAR(8) NOT NULL,
			created_at DATETIME NOT NULL,
			updated_at DATETIME NOT NULL,
			PRIMARY KEY (id),
			UNIQUE KEY uniq_site_order (site_id, remote_order_id),
			KEY idx_site (site_id),
			KEY idx_created (created_at)
		) {$charset_collate};";

		foreach ( $sql as $statement ) {
			dbDelta( $statement );
		}
	}
}



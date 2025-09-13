<?php

namespace ZeusWeb\Multishop\DB;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Tables {
	public static function keys(): string {
		global $wpdb;
		return $wpdb->prefix . 'zw_ms_keys';
	}

	public static function backorders(): string {
		global $wpdb;
		return $wpdb->prefix . 'zw_ms_backorders';
	}

	public static function sites(): string {
		global $wpdb;
		return $wpdb->prefix . 'zw_ms_sites';
	}

	public static function logs(): string {
		global $wpdb;
		return $wpdb->prefix . 'zw_ms_logs';
	}
}



<?php

namespace ZeusWeb\Multishop\Logger;

use ZeusWeb\Multishop\DB\Tables;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class DBLogger {
	public static function init(): void {
		add_action( 'zw_ms/log', [ __CLASS__, 'handle' ], 10, 3 );
	}

	public static function handle( string $level, string $message, array $context = [] ): void {
		global $wpdb;
		$table = Tables::logs();
		$wpdb->insert(
			$table,
			[
				'timestamp' => current_time( 'mysql', 1 ),
				'level'     => strtolower( $level ),
				'message'   => $message,
				'context'   => wp_json_encode( $context ),
				'site_id'   => isset( $context['site_id'] ) ? (string) $context['site_id'] : null,
				'order_ref' => isset( $context['order_ref'] ) ? (string) $context['order_ref'] : null,
			],
			[ '%s', '%s', '%s', '%s', '%s', '%s' ]
		);
	}
}



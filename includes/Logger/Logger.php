<?php

namespace ZeusWeb\Multishop\Logger;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Logger {
	/** @var Logger */
	private static $instance;

	public static function instance(): Logger {
		if ( ! isset( self::$instance ) ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	public function log( string $level, string $message, array $context = [] ): void {
		$entry = wp_json_encode( [
			'timestamp' => gmdate( 'c' ),
			'level'     => strtolower( $level ),
			'message'   => $message,
			'context'   => $context,
		] );
		/**
		 * Filter to capture plugin logs externally (e.g., to DB). Will be implemented later.
		 */
		do_action( 'zw_ms/log', $level, $message, $context );
		// Fallback to error_log to ensure visibility from day one.
		error_log( 'ZW-MS ' . $entry );
	}
}



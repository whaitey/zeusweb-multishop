<?php

namespace ZeusWeb\Multishop\Emails;

use ZeusWeb\Multishop\Logger\Logger;

if ( ! defined( 'ABSPATH' ) ) { exit; }

class Queue {
    private const OPTION_KEY = 'zw_ms_email_queue';
    private const EVENT_HOOK = 'zw_ms_queue_dispatch';
    private const SPACING_SECONDS = 10;

    public static function init(): void {
        add_action( self::EVENT_HOOK, [ __CLASS__, 'dispatch' ] );
    }

    public static function enqueue( array $item ): void {
        $queue = get_option( self::OPTION_KEY, [] );
        if ( ! is_array( $queue ) ) { $queue = []; }
        $queue[] = [
            'order_id' => isset( $item['order_id'] ) ? (int) $item['order_id'] : 0,
            'type'     => isset( $item['type'] ) ? (string) $item['type'] : 'completed',
            'ts'       => time(),
        ];
        update_option( self::OPTION_KEY, $queue, false );
        if ( ! wp_next_scheduled( self::EVENT_HOOK ) ) {
            wp_schedule_single_event( time() + 1, self::EVENT_HOOK );
        }
    }

    public static function dispatch(): void {
        $queue = get_option( self::OPTION_KEY, [] );
        if ( ! is_array( $queue ) || empty( $queue ) ) { return; }
        $item = array_shift( $queue );
        update_option( self::OPTION_KEY, $queue, false );

        $order_id = isset( $item['order_id'] ) ? (int) $item['order_id'] : 0;
        $type     = isset( $item['type'] ) ? (string) $item['type'] : 'completed';
        if ( $order_id > 0 ) {
            self::send_woo_email( $order_id, $type );
        }

        // Schedule next item if any remain
        if ( ! empty( $queue ) ) {
            wp_schedule_single_event( time() + self::SPACING_SECONDS, self::EVENT_HOOK );
        }
    }

    private static function send_woo_email( int $order_id, string $type ): void {
        try {
            $order = wc_get_order( $order_id );
            if ( ! $order ) { return; }
            $emails = function_exists( 'WC' ) && WC()->mailer() ? WC()->mailer()->get_emails() : [];
            if ( empty( $emails ) ) { return; }
            $sent = false;
            foreach ( $emails as $email ) {
                if ( ! method_exists( $email, 'is_enabled' ) || ! $email->is_enabled() ) { continue; }
                if ( $type === 'completed' && $email instanceof \WC_Email_Customer_Completed_Order ) {
                    $email->trigger( $order->get_id() );
                    $sent = true;
                }
                if ( $type === 'processing' && $email instanceof \WC_Email_Customer_Processing_Order ) {
                    $email->trigger( $order->get_id() );
                    $sent = true;
                }
            }
            Logger::instance()->log( 'info', 'Email queue sent', [ 'order_id' => $order_id, 'type' => $type, 'sent' => $sent ? 1 : 0 ] );
        } catch ( \Throwable $e ) {
            Logger::instance()->log( 'error', 'Email queue send failed', [ 'order_id' => $order_id, 'type' => $type, 'error' => $e->getMessage() ] );
        }
    }
}



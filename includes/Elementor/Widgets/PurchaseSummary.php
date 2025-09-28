<?php

namespace ZeusWeb\Multishop\Elementor\Widgets;

use Elementor\Widget_Base;
use Elementor\Controls_Manager;

if ( ! defined( 'ABSPATH' ) ) { exit; }

class PurchaseSummary extends Widget_Base {
    public function get_name() {
        return 'zw_ms_purchase_summary';
    }

    public function get_title() {
        return __( 'Multishop: Purchase Summary', 'zeusweb-multishop' );
    }

    public function get_icon() {
        return 'eicon-checkout';
    }

    public function get_categories() {
        return [ 'general' ];
    }

    protected function register_controls() {
        $this->start_controls_section( 'section_content', [ 'label' => __( 'Content', 'zeusweb-multishop' ) ] );
        $this->add_control( 'heading_text', [
            'label' => __( 'Heading', 'zeusweb-multishop' ),
            'type' => Controls_Manager::TEXT,
            'default' => __( 'Thank you for your purchase', 'zeusweb-multishop' ),
        ] );
        $this->add_control( 'show_billing', [
            'label' => __( 'Show billing details', 'zeusweb-multishop' ),
            'type' => Controls_Manager::SWITCHER,
            'default' => 'yes',
        ] );
        $this->add_control( 'show_shipping', [
            'label' => __( 'Show shipping details', 'zeusweb-multishop' ),
            'type' => Controls_Manager::SWITCHER,
            'default' => 'yes',
        ] );
        $this->add_control( 'accent_color', [
            'label' => __( 'Accent Color', 'zeusweb-multishop' ),
            'type' => Controls_Manager::COLOR,
            'default' => '#4f46e5',
        ] );
        $this->end_controls_section();
    }

    protected function render() {
        $settings = $this->get_settings_for_display();
        $order_id = 0;
        if ( isset( $_GET['key'] ) && function_exists( 'wc_get_order_id_by_order_key' ) ) {
            $oid = wc_get_order_id_by_order_key( sanitize_text_field( wp_unslash( $_GET['key'] ) ) );
            $order_id = $oid ? (int) $oid : 0;
        }
        if ( ! $order_id && isset( $_GET['order_id'] ) ) { $order_id = absint( $_GET['order_id'] ); }
        $order = $order_id ? wc_get_order( $order_id ) : null;
        if ( ! $order ) {
            echo '<div class="zwms-purchase-summary">' . esc_html__( 'Order not found.', 'zeusweb-multishop' ) . '</div>';
            return;
        }

        $accent = isset( $settings['accent_color'] ) ? $settings['accent_color'] : '#4f46e5';
        $heading = isset( $settings['heading_text'] ) ? $settings['heading_text'] : __( 'Thank you for your purchase', 'zeusweb-multishop' );

        echo '<div class="zwms-purchase-summary" style="--zwms-accent:' . esc_attr( $accent ) . ';font-family:Inter,system-ui,-apple-system,Segoe UI,Roboto,Helvetica,Arial,sans-serif;color:#0f172a;">';
        echo '<style>
        .zwms-card{background:#fff;border:1px solid #e5e7eb;border-radius:14px;box-shadow:0 10px 20px rgba(2,6,23,0.06);overflow:hidden}
        .zwms-header{display:flex;align-items:center;gap:12px;padding:18px 20px;background:linear-gradient(180deg,rgba(79,70,229,0.08),transparent)}
        .zwms-badge{background:var(--zwms-accent);color:#fff;border-radius:999px;padding:6px 10px;font-weight:600;font-size:12px;letter-spacing:.3px;text-transform:uppercase}
        .zwms-title{margin:0;font-size:22px;font-weight:700;color:#0f172a}
        .zwms-body{padding:18px 20px}
        .zwms-grid{display:grid;grid-template-columns:1fr 1fr;gap:16px}
        .zwms-section h3{margin:0 0 8px 0;font-size:14px;font-weight:700;color:#334155;text-transform:uppercase;letter-spacing:.4px}
        .zwms-key{background:#0f172a;color:#e2e8f0;border-radius:10px;padding:10px 12px;font-family:ui-monospace,SFMono-Regular,Menlo,Monaco,Consolas,monospace;font-size:13px}
        .zwms-item{border-top:1px dashed #e2e8f0;padding-top:12px;margin-top:12px}
        .zwms-note{color:#475569;font-size:13px}
        @media (max-width:768px){.zwms-grid{grid-template-columns:1fr}}
        </style>';

        echo '<div class="zwms-card">';
        echo '<div class="zwms-header"><span class="zwms-badge">' . esc_html__( 'Order', 'zeusweb-multishop' ) . '</span><h2 class="zwms-title">' . esc_html( $heading ) . '</h2></div>';
        echo '<div class="zwms-body">';

        echo '<div class="zwms-grid">';
        // Left: items + keys
        echo '<div class="zwms-section">';
        echo '<h3>' . esc_html__( 'Items & Keys', 'zeusweb-multishop' ) . '</h3>';
        foreach ( $order->get_items() as $item_id => $item ) {
            $product = $item->get_product();
            $title = $product ? $product->get_name() : (string) $item_id;
            $qty = (int) $item->get_quantity();
            $keys = (string) wc_get_order_item_meta( $item_id, '_zw_ms_keys', true );
            $shortage = (string) wc_get_order_item_meta( $item_id, '_zw_ms_shortage', true );
            echo '<div class="zwms-item">';
            echo '<div style="display:flex;justify-content:space-between;gap:12px;align-items:center"><strong>' . esc_html( $title ) . '</strong><span class="zwms-note">x' . esc_html( (string) $qty ) . '</span></div>';
            if ( $keys !== '' ) {
                $lines = preg_split( "/\r\n|\r|\n/", $keys );
                foreach ( $lines as $k ) {
                    $k = trim( (string) $k ); if ( $k === '' ) { continue; }
                    echo '<div class="zwms-key">' . esc_html( $k ) . '</div>';
                }
            }
            if ( $shortage !== '' ) {
                echo '<p class="zwms-note"><em>' . wp_kses_post( $shortage ) . '</em></p>';
            }
            echo '</div>';
        }
        echo '</div>';

        // Right: summary
        echo '<div class="zwms-section">';
        echo '<h3>' . esc_html__( 'Summary', 'zeusweb-multishop' ) . '</h3>';
        echo '<p class="zwms-note">' . esc_html__( 'Order number', 'zeusweb-multishop' ) . ': ' . esc_html( $order->get_order_number() ) . '</p>';
        echo '<p class="zwms-note">' . esc_html__( 'Date', 'zeusweb-multishop' ) . ': ' . esc_html( wc_format_datetime( $order->get_date_created() ) ) . '</p>';
        echo '<p class="zwms-note">' . esc_html__( 'Total', 'zeusweb-multishop' ) . ': ' . wp_kses_post( $order->get_formatted_order_total() ) . '</p>';
        if ( $settings['show_billing'] === 'yes' ) {
            echo '<h3>' . esc_html__( 'Billing details', 'zeusweb-multishop' ) . '</h3>';
            echo '<p class="zwms-note">' . wp_kses_post( $order->get_formatted_billing_address() ) . '</p>';
        }
        if ( $settings['show_shipping'] === 'yes' ) {
            echo '<h3>' . esc_html__( 'Shipping details', 'zeusweb-multishop' ) . '</h3>';
            echo '<p class="zwms-note">' . wp_kses_post( $order->get_formatted_shipping_address() ) . '</p>';
        }
        echo '</div>';

        echo '</div>'; // grid
        echo '</div>'; // body
        echo '</div>'; // card
        echo '</div>'; // wrapper
    }
}



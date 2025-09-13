<?php

namespace ZeusWeb\Multishop\Elementor;

use ZeusWeb\Multishop\Segments\Manager as SegmentManager;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Renderer {
	public static function init(): void {
		// Header/Footer injection: generic hooks for broad theme support
		add_action( 'wp_body_open', [ __CLASS__, 'render_header_template' ], 5 );
		add_action( 'get_header', function(){ ob_start(); }, 0 );
		add_action( 'wp_head', function(){ $buf = ob_get_clean(); echo $buf; }, 9999 );
		add_action( 'get_footer', function(){ ob_start(); }, 0 );
		add_action( 'wp_footer', function(){ $buf = ob_get_clean(); echo $buf; }, 0 );
		add_action( 'wp_footer', [ __CLASS__, 'render_footer_template' ], 5 );

		// Single product override using template_include to inject Elementor template content
		add_filter( 'template_include', [ __CLASS__, 'maybe_render_single_product_template' ], 99 );
	}

	private static function get_template_id( string $slot ): int {
		$segment = SegmentManager::is_business() ? 'business' : 'consumer';
		switch ( $slot . '_' . $segment ) {
			case 'header_consumer':
				return (int) get_option( 'zw_ms_tpl_header_consumer', 0 );
			case 'header_business':
				return (int) get_option( 'zw_ms_tpl_header_business', 0 );
			case 'footer_consumer':
				return (int) get_option( 'zw_ms_tpl_footer_consumer', 0 );
			case 'footer_business':
				return (int) get_option( 'zw_ms_tpl_footer_business', 0 );
			case 'single_product_consumer':
				return (int) get_option( 'zw_ms_tpl_single_product_consumer', 0 );
			case 'single_product_business':
				return (int) get_option( 'zw_ms_tpl_single_product_business', 0 );
		}
		return 0;
	}

	public static function render_header_template(): void {
		$id = self::get_template_id( 'header' );
		if ( $id ) {
			self::render_elementor_template( $id );
		}
	}

	public static function render_footer_template(): void {
		$id = self::get_template_id( 'footer' );
		if ( $id ) {
			self::render_elementor_template( $id );
		}
	}

	public static function maybe_render_single_product_template( $template ) {
		if ( function_exists( 'is_product' ) && is_product() ) {
			$id = self::get_template_id( 'single_product' );
			if ( $id ) {
				// Render inside the_content to preserve theme wrappers
				add_filter( 'the_content', function () use ( $id ) {
					ob_start();
					self::render_elementor_template( $id );
					return ob_get_clean();
				}, 9999 );
			}
		}
		return $template;
	}

	private static function render_elementor_template( int $template_id ): void {
		if ( $template_id <= 0 ) { return; }
		if ( did_action( 'elementor/loaded' ) ) {
			echo \Elementor\Plugin::instance()->frontend->get_builder_content_for_display( $template_id );
			return;
		}
		// Fallback via shortcode if Elementor not fully loaded here
		echo do_shortcode( '[elementor-template id="' . intval( $template_id ) . '"]' );
	}


}



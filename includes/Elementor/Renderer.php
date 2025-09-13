<?php

namespace ZeusWeb\Multishop\Elementor;

use ZeusWeb\Multishop\Segments\Manager as SegmentManager;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Renderer {
	public static function init(): void {
		// Header/Footer injection: for themes that support wp_body_open and wp_footer
		add_action( 'wp_body_open', [ __CLASS__, 'render_header_template' ], 5 );
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
				self::output_fullscreen_template( $id );
				// Return a minimal blank template to avoid double content
				return self::get_blank_template_path();
			}
		}
		return $template;
	}

	private static function render_elementor_template( int $template_id ): void {
		if ( did_action( 'elementor/loaded' ) ) {
			echo \Elementor\Plugin::instance()->frontend->get_builder_content_for_display( $template_id );
		}
	}

	private static function output_fullscreen_template( int $template_id ): void {
		get_header();
		self::render_elementor_template( $template_id );
		get_footer();
	}

	private static function get_blank_template_path(): string {
		// Use WordPress bundled blank template if available; fallback to plugin-provided minimal empty file.
		$blank = ABSPATH . WPINC . '/theme-compat/embed.php';
		return file_exists( $blank ) ? $blank : __FILE__;
	}
}


